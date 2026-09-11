<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Storefront\Controller;

use MartinKuhl\Sw6Oidc\Service\Oidc\LogoutContextStore;
use MartinKuhl\Sw6Oidc\Service\Oidc\OidcCallbackProcessor;
use MartinKuhl\Sw6Oidc\Service\Provisioning\CustomerProvisioningService;
use MartinKuhl\Sw6Oidc\Storefront\Service\OidcCustomerLoginRoute;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Step 2 of the Storefront customer OIDC flow: exchanges the code, verifies
 * the id_token, JIT-provisions the customer, and logs them in. Mirrors the
 * Magento module's ReadAuthorizationResponse -> CheckAttributeMappingAction ->
 * ProcessUserAction -> CustomerLoginAction -> CustomerOidcCallback chain,
 * collapsed into one controller since the Storefront callback is already a
 * clean, server-rendered HTTP context (no nonce/cookie hand-off needed).
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
#[Package('storefront')]
class OidcCallbackController extends StorefrontController
{
    public function __construct(
        private readonly OidcCallbackProcessor $callbackProcessor,
        private readonly CustomerProvisioningService $customerProvisioningService,
        private readonly OidcCustomerLoginRoute $loginRoute,
        private readonly SalesChannelContextService $salesChannelContextService,
        private readonly LogoutContextStore $logoutContextStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/sw6oidc/callback',
        name: 'frontend.sw6oidc.callback',
        defaults: ['XmlHttpRequest' => true, '_loginRequired' => false],
        methods: ['GET'],
    )]
    public function callback(Request $request, SalesChannelContext $context): Response
    {
        if ($request->query->get('error') !== null) {
            $this->logger->warning('sw6oidc: IdP returned an OAuth error on the customer callback.', [
                'error' => $request->query->get('error'),
                'error_description' => $request->query->get('error_description'),
            ]);

            $this->addFlash(self::DANGER, $this->trans('sw6oidc.login.failed'));

            return new RedirectResponse($this->generateUrl('frontend.account.login.page'));
        }

        $redirectUri = $this->generateUrl('frontend.sw6oidc.callback', [], UrlGeneratorInterface::ABSOLUTE_URL);

        try {
            $result = $this->callbackProcessor->process(
                $request->query->get('code'),
                $request->query->get('state'),
                $redirectUri,
                $context->getContext(),
            );

            $customer = $this->customerProvisioningService->findOrCreateCustomer($result->provider, $result->profile, $context);

            $tokenResponse = $this->loginRoute->login(new RequestDataBag(['email' => $customer->getEmail()]), $context);

            $newContext = $this->salesChannelContextService->get(new SalesChannelContextServiceParameters(
                $context->getSalesChannelId(),
                $tokenResponse->getToken(),
                $context->getLanguageIdChain()[0] ?? $context->getLanguageId(),
                $context->getCurrencyId(),
                $context->getDomainId(),
                $context->getContext(),
            ));

            // Lets Shopware's own context-token subscriber persist the new
            // sw-context-token cookie on this response, exactly as a normal
            // login would — no manual cookie handling needed here.
            $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT, $newContext);

            $this->logoutContextStore->remember(
                $tokenResponse->getToken(),
                $result->provider->getId(),
                \is_string($result->tokens['id_token'] ?? null) ? $result->tokens['id_token'] : null,
            );

            return new RedirectResponse($this->resolveSafeRelayState($result->flow->relayState));
        } catch (\Throwable $exception) {
            $this->logger->warning('sw6oidc: customer OIDC callback failed.', [
                'exception' => $exception->getMessage(),
            ]);

            $this->addFlash(self::DANGER, $this->trans('sw6oidc.login.failed'));

            return new RedirectResponse($this->generateUrl('frontend.account.login.page'));
        }
    }

    /**
     * Relay state is attacker-influenced (it's echoed back through the IdP
     * redirect), so only ever redirect to a path on this same request's host —
     * never to an absolute URL pointing somewhere else.
     */
    private function resolveSafeRelayState(string $relayState): string
    {
        if ($relayState === '' || str_starts_with($relayState, '//')) {
            return $this->generateUrl('frontend.account.home.page');
        }

        $parts = parse_url($relayState);

        if ($parts === false || isset($parts['scheme'], $parts['host'])) {
            // Absolute URL: only allow it through unchanged if it was one we
            // generated ourselves (i.e. the "path" survives re-parsing) —
            // simplest safe rule is to just take the path+query, dropping any
            // attacker-supplied scheme/host entirely.
            return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        }

        return $relayState;
    }
}
