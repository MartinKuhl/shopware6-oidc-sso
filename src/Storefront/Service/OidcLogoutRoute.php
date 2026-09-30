<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Storefront\Service;

use MartinKuhl\Sw6Oidc\Service\Oidc\LogoutContext;
use MartinKuhl\Sw6Oidc\Service\Oidc\LogoutContextStore;
use MartinKuhl\Sw6Oidc\Service\Oidc\RpInitiatedLogoutService;
use MartinKuhl\Sw6Oidc\Service\Provider\Exception\ProviderNotFoundException;
use MartinKuhl\Sw6Oidc\Service\Provider\ProviderResolver;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractLogoutRoute;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\ContextTokenResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Decorates core's LogoutRoute to resolve the IdP's RP-Initiated Logout URL
 * for customers who logged in via OIDC.
 *
 * This has to happen here rather than in a CustomerLogoutEvent listener: core
 * LogoutRoute replaces the sales-channel context with a fresh random token
 * *before* dispatching that event, so the event no longer carries the token
 * the LogoutContextStore entry was saved under at login. We capture it before
 * delegating and only consume the entry once the logout actually succeeded.
 *
 * The resolved URL is only handed to PendingLogoutRedirect; turning it into
 * a redirect is CustomerLogoutSubscriber's job (Storefront route only, so
 * Store API clients keep their JSON response).
 */
class OidcLogoutRoute extends AbstractLogoutRoute
{
    public function __construct(
        private readonly AbstractLogoutRoute $decorated,
        private readonly LogoutContextStore $logoutContextStore,
        private readonly ProviderResolver $providerResolver,
        private readonly RpInitiatedLogoutService $rpInitiatedLogoutService,
        private readonly PendingLogoutRedirect $pendingLogoutRedirect,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getDecorated(): AbstractLogoutRoute
    {
        return $this->decorated;
    }

    public function logout(SalesChannelContext $context, RequestDataBag $data): ContextTokenResponse
    {
        $preLogoutToken = $context->getToken();

        $response = $this->decorated->logout($context, $data);

        $logoutContext = $this->logoutContextStore->consume($preLogoutToken);

        if (!$logoutContext instanceof LogoutContext) {
            $this->logger->debug('sw6oidc: no OIDC logout context for this customer session, skipping RP-initiated logout.');

            return $response;
        }

        try {
            $provider = $this->providerResolver->getActiveById($logoutContext->providerId, $context->getContext());
        } catch (ProviderNotFoundException $exception) {
            $this->logger->warning('sw6oidc: RP-initiated logout skipped, provider no longer active.', [
                'providerId' => $logoutContext->providerId,
                'exception' => $exception->getMessage(),
            ]);

            return $response;
        }

        $this->rpInitiatedLogoutService->revokeToken($provider, null);

        $logoutUrl = $this->rpInitiatedLogoutService->buildLogoutUrl(
            $provider,
            $logoutContext->idToken,
            $this->urlGenerator->generate('frontend.account.login.page', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'customer:',
        );

        $this->logger->debug('sw6oidc: resolved customer RP-initiated logout URL.', [
            'providerId' => $logoutContext->providerId,
            'hasLogoutUrl' => $logoutUrl !== null,
        ]);

        $this->pendingLogoutRedirect->set($logoutUrl);

        return $response;
    }
}
