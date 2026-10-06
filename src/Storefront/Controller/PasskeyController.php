<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Storefront\Controller;

use MartinKuhl\Sw6Oidc\Core\Content\SessionActivity\Sw6OidcSessionActivityDefinition;
use MartinKuhl\Sw6Oidc\Event\PasskeyRegisteredEvent;
use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyAuthenticationService;
use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyConfig;
use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyRegistrationService;
use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyRelyingPartyResolver;
use MartinKuhl\Sw6Oidc\Service\Security\PublicError;
use MartinKuhl\Sw6Oidc\Service\Security\Sw6OidcRateLimiter;
use MartinKuhl\Sw6Oidc\Service\Session\SessionAuthenticationClock;
use MartinKuhl\Sw6Oidc\Service\Session\Sw6OidcSession;
use MartinKuhl\Sw6Oidc\Service\Session\Sw6OidcSessionActivityRecorder;
use MartinKuhl\Sw6Oidc\Storefront\Service\OidcCustomerLoginRoute;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Storefront customer Passkey self-service registration and usernameless
 * login.
 *
 * - All ceremony endpoints answer 404 while customer passkeys are disabled
 *   for the sales channel (H6).
 * - Registering needs a freshly authenticated *session* (within
 *   REAUTH_WINDOW_SECONDS, SessionAuthenticationClock); otherwise the
 *   customer is sent to re-authenticate first — a stolen session alone
 *   can't add a permanent way in (H7, R3-M5). Verification only
 *   completes for the customer who started the ceremony (N-M2), and the
 *   owner is notified via the PasskeyRegisteredEvent flow trigger.
 * - The relying party comes from the sales channel's domains (M17, N-M1).
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class PasskeyController extends StorefrontController
{
    /**
     * Shared with AccountPasskeyController::delete(), which checks whether
     * the credential being deleted matches this session-scoped marker.
     */
    public const SESSION_KEY_LOGIN_CREDENTIAL_ID = 'sw6oidc_login_credential_id';

    public const REAUTH_WINDOW_SECONDS = SessionAuthenticationClock::DEFAULT_WINDOW_SECONDS;

    public function __construct(
        private readonly PasskeyRegistrationService $registrationService,
        private readonly PasskeyAuthenticationService $authenticationService,
        private readonly PasskeyConfig $passkeyConfig,
        private readonly EntityRepository $customerRepository,
        private readonly OidcCustomerLoginRoute $loginRoute,
        private readonly SalesChannelContextService $salesChannelContextService,
        private readonly LoggerInterface $logger,
        private readonly Sw6OidcSessionActivityRecorder $activityRecorder,
        private readonly PasskeyRelyingPartyResolver $relyingPartyResolver,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly Sw6OidcRateLimiter $rateLimiter,
        private readonly SessionAuthenticationClock $authenticationClock,
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
        if (!$this->passkeyConfig->isEnabledForCustomer($context->getSalesChannelId())) {
            return $this->disabled();
        }

        if (!$this->authenticationClock->isFresh($context, self::REAUTH_WINDOW_SECONDS)) {
            return $this->reauthenticationRequired();
        }

        try {
            $result = $this->registrationService->buildCreationOptions(
                'customer',
                $customer->getId(),
                $customer->getEmail(),
                trim($customer->getFirstName() . ' ' . $customer->getLastName()),
                $this->relyingPartyResolver->forSalesChannel($context),
            );
        } catch (\Throwable $exception) {
            return PublicError::response($this->logger, 'sw6oidc: passkey registration could not start.', $exception, 'passkey_unavailable', Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['sessionId' => $result['nonce'], 'options' => json_decode($result['optionsJson'], true)]);
    }

    #[Route(
        path: '/sw6oidc/passkey/registration-verify',
        name: 'frontend.sw6oidc.passkey.registration-verify',
        defaults: ['XmlHttpRequest' => true, '_loginRequired' => true],
        methods: ['POST'],
    )]
    public function registrationVerify(Request $request, SalesChannelContext $context, CustomerEntity $customer): JsonResponse
    {
        if (!$this->passkeyConfig->isEnabledForCustomer($context->getSalesChannelId())) {
            return $this->disabled();
        }

        if (!$this->authenticationClock->isFresh($context, self::REAUTH_WINDOW_SECONDS)) {
            return $this->reauthenticationRequired();
        }

        $nickname = $request->request->get('nickname') !== null ? mb_substr((string) $request->request->get('nickname'), 0, 255) : null;

        try {
            $this->registrationService->verifyAndPersist(
                (string) $request->request->get('sessionId'),
                (string) $request->request->get('credential'),
                $request->getHost(),
                $nickname,
                'customer',
                $customer->getId(),
            );
        } catch (\Throwable $exception) {
            return PublicError::response($this->logger, 'sw6oidc: passkey registration failed.', $exception, 'passkey_registration_failed', Response::HTTP_BAD_REQUEST);
        }

        $this->eventDispatcher->dispatch(new PasskeyRegisteredEvent(
            'customer',
            $customer->getId(),
            $customer->getEmail(),
            trim($customer->getFirstName() . ' ' . $customer->getLastName()),
            $nickname,
            $context->getSalesChannelId(),
            $context->getContext(),
        ));

        return new JsonResponse(['status' => true]);
    }

    #[Route(
        path: '/sw6oidc/passkey/login-options',
        name: 'frontend.sw6oidc.passkey.login-options',
        defaults: ['XmlHttpRequest' => true, '_loginRequired' => false],
        methods: ['POST'],
    )]
    public function loginOptions(Request $request, SalesChannelContext $context): JsonResponse
    {
        if (!$this->passkeyConfig->isEnabledForCustomer($context->getSalesChannelId())) {
            return $this->disabled();
        }

        // Every call stores a ceremony: a consuming budget (N-M15).
        if (!$this->rateLimiter->consume(Sw6OidcRateLimiter::SCOPE_OPTIONS, $request->getClientIp())) {
            return $this->rateLimited();
        }

        try {
            // Empty allowCredentials: usernameless/discoverable login, the
            // browser resolves the matching passkey itself.
            $result = $this->authenticationService->buildRequestOptions(
                [],
                $this->relyingPartyResolver->forSalesChannel($context),
                PasskeyAuthenticationService::storefrontLoginPurpose($context->getSalesChannelId()),
            );
        } catch (\Throwable $exception) {
            return PublicError::response($this->logger, 'sw6oidc: passkey login could not start.', $exception, 'passkey_unavailable', Response::HTTP_BAD_REQUEST);
        }

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
        if (!$this->passkeyConfig->isEnabledForCustomer($context->getSalesChannelId())) {
            return $this->disabled();
        }

        if ($this->rateLimiter->isBlocked(Sw6OidcRateLimiter::SCOPE_REDEEM, $request->getClientIp())) {
            return $this->rateLimited();
        }

        try {
            $resolved = $this->authenticationService->verifyAssertion(
                (string) $request->request->get('sessionId'),
                (string) $request->request->get('credential'),
                $request->getHost(),
                PasskeyAuthenticationService::storefrontLoginPurpose($context->getSalesChannelId()),
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

            $tokenResponse = $this->loginRoute->loginByCustomerId($customer->getId(), $context);
        } catch (\Throwable $exception) {
            $this->rateLimiter->recordFailure(Sw6OidcRateLimiter::SCOPE_REDEEM, $request->getClientIp());

            return PublicError::response($this->logger, 'sw6oidc: passkey login failed.', $exception, 'passkey_login_failed', Response::HTTP_UNAUTHORIZED);
        }

        $newContext = $this->salesChannelContextService->get(new SalesChannelContextServiceParameters(
            $context->getSalesChannelId(),
            $tokenResponse->getToken(),
            $context->getLanguageIdChain()[0] ?? $context->getLanguageId(),
            $context->getCurrencyId(),
            $context->getDomainId(),
            $context->getContext(),
        ));

        $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT, $newContext);

        $this->activityRecorder->recordLogin(
            Sw6OidcSession::USER_TYPE_CUSTOMER,
            $customer->getId(),
            Sw6OidcSessionActivityDefinition::LOGIN_METHOD_PASSKEY,
            $tokenResponse->getToken(),
            $request,
            passkeyCredentialHash: $resolved['credentialIdHash'],
        );

        // Remembered for the lifetime of this browser session so
        // AccountPasskeyController::delete() can tell "deleted the passkey
        // authenticating this session" from "deleted another passkey".
        $request->getSession()->set(self::SESSION_KEY_LOGIN_CREDENTIAL_ID, $resolved['credentialId']);

        return new JsonResponse(['status' => true]);
    }

    private function reauthenticationRequired(): JsonResponse
    {
        return new JsonResponse([
            'error' => 'reauthentication_required',
            'reauthUrl' => $this->generateUrl('frontend.sw6oidc.reauth'),
        ], Response::HTTP_FORBIDDEN);
    }

    private function rateLimited(): JsonResponse
    {
        return new JsonResponse(['error' => 'too_many_requests'], Response::HTTP_TOO_MANY_REQUESTS);
    }

    private function disabled(): JsonResponse
    {
        return new JsonResponse(['error' => 'passkeys_disabled'], Response::HTTP_NOT_FOUND);
    }
}
