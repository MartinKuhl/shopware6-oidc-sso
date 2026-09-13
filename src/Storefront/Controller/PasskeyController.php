<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Storefront\Controller;

use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyAuthenticationService;
use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyConfig;
use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyRegistrationService;
use MartinKuhl\Sw6Oidc\Storefront\Service\OidcCustomerLoginRoute;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Storefront customer Passkey self-service registration + usernameless login
 * — independent of OIDC, backed directly by web-auth/webauthn-lib. Mirrors
 * the Magento module's Controller/Actions/Passkey/* controllers.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class PasskeyController extends StorefrontController
{
    /**
     * Shared with AccountPasskeyController::delete(), which checks whether
     * the credential being deleted matches this session-scoped marker.
     */
    public const SESSION_KEY_LOGIN_CREDENTIAL_ID = 'sw6oidc_login_credential_id';

    public function __construct(
        private readonly PasskeyRegistrationService $registrationService,
        private readonly PasskeyAuthenticationService $authenticationService,
        private readonly PasskeyConfig $passkeyConfig,
        private readonly EntityRepository $customerRepository,
        private readonly OidcCustomerLoginRoute $loginRoute,
        private readonly SalesChannelContextService $salesChannelContextService,
        private readonly PsrHttpFactory $psrHttpFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/sw6oidc/passkey/registration-options',
        name: 'frontend.sw6oidc.passkey.registration-options',
        defaults: ['XmlHttpRequest' => true, '_loginRequired' => true],
        methods: ['POST'],
    )]
    public function registrationOptions(SalesChannelContext $context, CustomerEntity $customer): JsonResponse
    {
        $result = $this->registrationService->buildCreationOptions(
            'customer',
            $customer->getId(),
            $customer->getEmail(),
            trim($customer->getFirstName() . ' ' . $customer->getLastName()),
            $this->rpId($context),
            $this->passkeyConfig->getRpName($context->getSalesChannel()->getTranslated()['name'] ?? 'Shop'),
        );

        return new JsonResponse(['sessionId' => $result['nonce'], 'options' => json_decode($result['optionsJson'], true)]);
    }

    #[Route(
        path: '/sw6oidc/passkey/registration-verify',
        name: 'frontend.sw6oidc.passkey.registration-verify',
        defaults: ['XmlHttpRequest' => true, '_loginRequired' => true],
        methods: ['POST'],
    )]
    public function registrationVerify(Request $request): JsonResponse
    {
        try {
            $this->registrationService->verifyAndPersist(
                (string) $request->request->get('sessionId'),
                (string) $request->request->get('credential'),
                $this->psrHttpFactory->createRequest($request),
                $request->request->get('nickname') !== null ? (string) $request->request->get('nickname') : null,
            );

            return new JsonResponse(['status' => true]);
        } catch (\Throwable $exception) {
            $this->logger->warning('sw6oidc: passkey registration failed.', ['exception' => $exception->getMessage()]);

            return new JsonResponse(['status' => false, 'message' => $exception->getMessage()], 400);
        }
    }

    #[Route(
        path: '/sw6oidc/passkey/login-options',
        name: 'frontend.sw6oidc.passkey.login-options',
        defaults: ['XmlHttpRequest' => true, '_loginRequired' => false],
        methods: ['POST'],
    )]
    public function loginOptions(SalesChannelContext $context): JsonResponse
    {
        // Empty allowCredentials: usernameless/discoverable login, the browser
        // resolves the matching passkey itself.
        $result = $this->authenticationService->buildRequestOptions([], $this->rpId($context));

        return new JsonResponse(['sessionId' => $result['nonce'], 'options' => json_decode($result['optionsJson'], true)]);
    }

    #[Route(
        path: '/sw6oidc/passkey/login-verify',
        name: 'frontend.sw6oidc.passkey.login-verify',
        defaults: ['XmlHttpRequest' => true, '_loginRequired' => false],
        methods: ['POST'],
    )]
    public function loginVerify(Request $request, SalesChannelContext $context): JsonResponse
    {
        try {
            $resolved = $this->authenticationService->verifyAssertion(
                (string) $request->request->get('sessionId'),
                (string) $request->request->get('credential'),
                $this->psrHttpFactory->createRequest($request),
            );

            if ($resolved['userType'] !== 'customer' || !Uuid::isValid($resolved['userId'])) {
                throw new \RuntimeException('This passkey is not registered to a customer account.');
            }

            $customer = $this->customerRepository->search(
                new Criteria([$resolved['userId']]),
                $context->getContext(),
            )->first();

            if (!$customer instanceof CustomerEntity) {
                throw new \RuntimeException('The customer for this passkey no longer exists.');
            }

            $tokenResponse = $this->loginRoute->login(new RequestDataBag(['email' => $customer->getEmail()]), $context);

            $newContext = $this->salesChannelContextService->get(new SalesChannelContextServiceParameters(
                $context->getSalesChannelId(),
                $tokenResponse->getToken(),
                $context->getLanguageIdChain()[0] ?? $context->getLanguageId(),
                $context->getCurrencyId(),
                $context->getDomainId(),
                $context->getContext(),
            ));

            $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT, $newContext);

            // Remembered for the lifetime of this browser session so
            // AccountPasskeyController::delete() can tell "the customer just
            // deleted the exact passkey that's authenticating them right
            // now" from "deleted some other, unrelated passkey of theirs" -
            // only the former should force an immediate logout.
            $request->getSession()->set(self::SESSION_KEY_LOGIN_CREDENTIAL_ID, $resolved['credentialId']);

            return new JsonResponse(['status' => true]);
        } catch (\Throwable $exception) {
            $this->logger->warning('sw6oidc: passkey login failed.', ['exception' => $exception->getMessage()]);

            return new JsonResponse(['status' => false, 'message' => $exception->getMessage()], 401);
        }
    }

    private function rpId(SalesChannelContext $context): string
    {
        $host = parse_url($context->getSalesChannel()->getDomains()?->first()?->getUrl() ?? '', PHP_URL_HOST) ?: '';

        return $this->passkeyConfig->getRpId($host);
    }
}
