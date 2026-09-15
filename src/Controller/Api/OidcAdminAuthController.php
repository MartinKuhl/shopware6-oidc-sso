<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Controller\Api;

use League\OAuth2\Server\AuthorizationServer;
use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Core\Content\UserProvider\Sw6OidcUserProviderEntity;
use MartinKuhl\Sw6Oidc\Service\AdminAuth\AdminLoginNonceService;
use MartinKuhl\Sw6Oidc\Service\AdminAuth\AdminOidcGrant;
use MartinKuhl\Sw6Oidc\Service\Oidc\AuthorizationRequestBuilder;
use MartinKuhl\Sw6Oidc\Service\Oidc\OidcCallbackProcessor;
use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyConfig;
use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyCredentialRepository;
use MartinKuhl\Sw6Oidc\Service\Provider\Exception\ProviderNotFoundException;
use MartinKuhl\Sw6Oidc\Service\Provider\ProviderResolver;
use MartinKuhl\Sw6Oidc\Service\Provisioning\AdminProvisioningService;
use MartinKuhl\Sw6Oidc\Service\Provisioning\Exception\AdminProvisioningDeniedException;
use MartinKuhl\Sw6Oidc\Service\Provisioning\UserProviderBindingService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The Administration OIDC bridge: SP-initiated login, IdP callback, and the
 * nonce-exchange endpoint the `sw-login` override calls to obtain a real
 * Shopware admin access token — see the plan's "bridging pattern" and
 * "Administration (Backend user) OIDC flow" sections for the full picture of
 * why this needs three legs instead of one.
 */
#[Route(defaults: ['_routeScope' => ['api'], 'auth_required' => false])]
class OidcAdminAuthController extends AbstractController
{
    public function __construct(
        private readonly ProviderResolver $providerResolver,
        private readonly AuthorizationRequestBuilder $requestBuilder,
        private readonly OidcCallbackProcessor $callbackProcessor,
        private readonly AdminProvisioningService $adminProvisioningService,
        private readonly AdminLoginNonceService $loginNonceService,
        private readonly AuthorizationServer $adminAuthorizationServer,
        private readonly PsrHttpFactory $psrHttpFactory,
        private readonly string $administrationBaseUrl,
        private readonly LoggerInterface $logger,
        private readonly PasskeyConfig $passkeyConfig,
        private readonly PasskeyCredentialRepository $passkeyCredentialRepository,
        private readonly UserProviderBindingService $bindingService,
    ) {
    }

    /**
     * Called by the `sw-login` override on mount so it only shows the "Login
     * with SSO"/"Login with Passkey" buttons when something is actually
     * configured — this endpoint is anonymous (it runs before any user is
     * known), so "available" here only ever means "at least one active
     * admin-scoped provider exists" / "passkey login is enabled AND at least
     * one admin has actually registered one", never anything about which
     * passkey a not-yet-identified visitor specifically holds. The passkey
     * enabled-toggle alone isn't enough - a freshly-enabled instance with
     * zero registered admin passkeys would otherwise show a button that's
     * guaranteed to fail for literally everyone until someone registers one.
     */
    #[Route(
        path: '/api/sw6oidc/admin/login-options',
        name: 'api.action.sw6oidc.admin.login-options',
        methods: ['GET'],
    )]
    public function loginOptions(): JsonResponse
    {
        $context = Context::createDefaultContext();

        return new JsonResponse([
            // One entry per visible admin-scoped provider, ordered by
            // sortOrder - `label` is null (rather than a hardcoded generic
            // string) when the provider has no displayName, so the JS side
            // can fall back to its own translated "Login with SSO" text.
            'ssoProviders' => array_map(
                static fn (Sw6OidcProviderEntity $provider): array => [
                    'id' => $provider->getId(),
                    'label' => $provider->getDisplayName(),
                ],
                $this->providerResolver->getVisibleProviders('admin', $context),
            ),
            'passkeyAvailable' => $this->passkeyConfig->isEnabledForAdmin()
                && $this->passkeyCredentialRepository->existsForUserType('admin', $context),
        ]);
    }

    #[Route(
        path: '/api/sw6oidc/admin/login',
        name: 'api.action.sw6oidc.admin.login',
        methods: ['GET'],
    )]
    public function login(Request $request): RedirectResponse
    {
        $context = Context::createDefaultContext();
        $providerId = $request->query->get('providerId');

        try {
            $provider = $providerId !== null
                ? $this->providerResolver->getActiveById((string) $providerId, $context)
                : $this->providerResolver->resolveDefault('admin', $context);
        } catch (ProviderNotFoundException $exception) {
            $this->logger->warning('sw6oidc: admin SSO login requested but no active provider is configured.', [
                'exception' => $exception->getMessage(),
            ]);

            return new RedirectResponse($this->administrationLoginUrl(['sw6oidc_error' => 'provider_unavailable']));
        }

        $redirectUri = $this->generateUrl('api.action.sw6oidc.admin.callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $authorizeUrl = $this->requestBuilder->build($provider, 'admin', '', $redirectUri);

        return new RedirectResponse($authorizeUrl);
    }

    #[Route(
        path: '/api/sw6oidc/admin/callback',
        name: 'api.action.sw6oidc.admin.callback',
        methods: ['GET'],
    )]
    public function callback(Request $request): RedirectResponse
    {
        $context = Context::createDefaultContext();

        if ($request->query->get('error') !== null) {
            $this->logger->warning('sw6oidc: IdP returned an OAuth error on the admin callback.', [
                'error' => $request->query->get('error'),
                'error_description' => $request->query->get('error_description'),
            ]);

            return new RedirectResponse($this->administrationLoginUrl(['sw6oidc_error' => 'oidc_failed']));
        }

        $redirectUri = $this->generateUrl('api.action.sw6oidc.admin.callback', [], UrlGeneratorInterface::ABSOLUTE_URL);

        try {
            $result = $this->callbackProcessor->process(
                $request->query->get('code'),
                $request->query->get('state'),
                $redirectUri,
                $context,
            );

            $adminUser = $this->adminProvisioningService->findOrCreateAdmin($result->provider, $result->profile, $context);

            $this->logger->debug('sw6oidc: admin user resolved, minting login nonce.', [
                'providerId' => $result->provider->getId(),
                'userId' => $adminUser->getId(),
            ]);

            $nonce = $this->loginNonceService->createNonce($adminUser->getId());
            $redirectUrl = $this->administrationLoginUrl(['sw6oidc_nonce' => $nonce]);

            $this->logger->debug('sw6oidc: admin OIDC callback succeeded, redirecting back into the Administration SPA.', [
                'userId' => $adminUser->getId(),
                'redirectUrl' => $redirectUrl,
            ]);

            return new RedirectResponse($redirectUrl);
        } catch (AdminProvisioningDeniedException $exception) {
            $this->logger->warning('sw6oidc: admin OIDC callback failed.', [
                'exception' => $exception->getMessage(),
                'reason' => $exception->reason,
                'providerId' => $result->provider->getId(),
                'groups' => $result->profile->groups,
            ]);

            return new RedirectResponse($this->administrationLoginUrl([
                'sw6oidc_error' => $exception->reason === AdminProvisioningDeniedException::REASON_NO_ROLE
                    ? 'admin_role_missing'
                    : 'admin_auto_create_disabled',
            ]));
        } catch (\Throwable $exception) {
            $this->logger->warning('sw6oidc: admin OIDC callback failed.', [
                'exceptionClass' => $exception::class,
                'exception' => $exception->getMessage(),
                // Often the actually-useful detail: e.g. a DB/DBAL exception
                // wraps the driver's own message (missing column, FK
                // violation, ...) as the previous exception rather than in
                // its own getMessage().
                'previousException' => $exception->getPrevious()?->getMessage(),
            ]);

            return new RedirectResponse($this->administrationLoginUrl(['sw6oidc_error' => 'oidc_failed']));
        }
    }

    /**
     * Called by the `sw-login` Administration override via XHR once it spots
     * `?sw6oidc_nonce=` in its own URL. Redeems the nonce, marks the live
     * request with the resolved admin user id, and runs it through the exact
     * same in-process AuthorizationServer bridge Shopware's own password grant
     * uses — the JSON body returned here *is* a normal OAuth2 token response.
     */
    #[Route(
        path: '/api/sw6oidc/admin/token',
        name: 'api.action.sw6oidc.admin.token',
        methods: ['POST'],
    )]
    public function exchangeNonce(Request $request): Response
    {
        $nonce = $request->request->get('sw6oidc_nonce');
        $userId = $this->loginNonceService->redeemNonce(\is_string($nonce) ? $nonce : null);

        if ($userId === null) {
            // Was silent before - a redirect back from the IdP that looks
            // clean in the logs above (provisioning succeeded, nonce
            // minted, redirect issued) can still end here if the SPA calls
            // this twice (nonces are single-use) or the redirect round-trip
            // took long enough for the 120s TTL to lapse.
            $this->logger->warning('sw6oidc: admin token exchange rejected - unknown, expired, or already-used nonce.', [
                'hasNonce' => \is_string($nonce) && $nonce !== '',
            ]);

            return $this->json(['error' => 'invalid_grant', 'error_description' => 'Unknown, expired, or already-used login nonce.'], 400);
        }

        $this->logger->debug('sw6oidc: admin login nonce redeemed, exchanging for an access token.', [
            'userId' => $userId,
        ]);

        $request->attributes->set(AdminOidcGrant::REQUEST_ATTRIBUTE_USER_ID, $userId);
        $request->request->set('grant_type', AdminOidcGrant::GRANT_IDENTIFIER);

        $psrRequest = $this->psrHttpFactory->createRequest($request);
        $psrResponse = $this->psrHttpFactory->createResponse(new Response());

        try {
            $tokenResponse = $this->adminAuthorizationServer->respondToAccessTokenRequest($psrRequest, $psrResponse);
        } catch (\League\OAuth2\Server\Exception\OAuthServerException $exception) {
            $this->logger->warning('sw6oidc: admin token exchange failed.', [
                'userId' => $userId,
                'exceptionClass' => $exception::class,
                'exception' => $exception->getMessage(),
                'previousException' => $exception->getPrevious()?->getMessage(),
            ]);

            return $this->json(['error' => 'invalid_grant', 'error_description' => $exception->getMessage()], 400);
        }

        $this->logger->debug('sw6oidc: admin token exchange succeeded.', ['userId' => $userId]);

        return (new HttpFoundationFactory())->createResponse($tokenResponse);
    }

    /**
     * Mints a fresh access/refresh token pair carrying the `user-verified`
     * OAuth scope for the *currently authenticated* Administration user,
     * without asking for a password — Shopware's own "confirm your password"
     * modal (shown on every profile save, and independently re-enforced
     * server-side for any user with `user:editor` rights, which includes
     * every superadmin) has no way to succeed for an account this plugin
     * provisioned, since its password is a random, permanently unknown
     * value (see AdminProvisioningService::create()). The `sw-profile`
     * override calls this first and only falls back to the real password
     * modal if it 403s.
     *
     * Requires an already-valid bearer token (`auth_required: true`,
     * overriding the class-level default) — the acting user id comes from
     * that token's own resolved AdminApiSource, never from client input —
     * and additionally requires the account to actually be OIDC- or
     * Passkey-provisioned (bound in sw6oidc_user_provider, or owning at
     * least one sw6oidc_passkey_credential row), so a normal local-password
     * admin can't use this to skip their own password reconfirmation.
     *
     * The minted token is otherwise identical to a normal login token (same
     * AdminOidcGrant, same 10-minute TTL, same write/admin scope
     * resolution) — only `user-verified` is new. See AdminOidcGrant's own
     * docblock for why passing that trust through this bridge is
     * appropriate: this grant already fully vouches for the user via a
     * pre-verified OIDC/Passkey login, the same trust level Shopware's own
     * password check provides.
     */
    #[Route(
        path: '/api/sw6oidc/admin/verify-session',
        name: 'api.action.sw6oidc.admin.verify-session',
        defaults: ['auth_required' => true],
        methods: ['POST'],
    )]
    public function verifySession(Request $request, Context $context): Response
    {
        $source = $context->getSource();
        $userId = $source instanceof AdminApiSource ? $source->getUserId() : null;

        if ($userId === null) {
            return $this->json(['error' => 'invalid_request', 'error_description' => 'No authenticated Administration user.'], 401);
        }

        if (!$this->isSsoProvisioned($userId, $context)) {
            return $this->json(['error' => 'invalid_request', 'error_description' => 'This account was not authenticated via OIDC/Passkey SSO.'], 403);
        }

        $request->attributes->set(AdminOidcGrant::REQUEST_ATTRIBUTE_USER_ID, $userId);
        $request->request->set('grant_type', AdminOidcGrant::GRANT_IDENTIFIER);
        $request->request->set('client_id', 'administration');
        $request->request->set('scope', 'user-verified');

        $psrRequest = $this->psrHttpFactory->createRequest($request);
        $psrResponse = $this->psrHttpFactory->createResponse(new Response());

        try {
            $tokenResponse = $this->adminAuthorizationServer->respondToAccessTokenRequest($psrRequest, $psrResponse);
        } catch (\League\OAuth2\Server\Exception\OAuthServerException $exception) {
            $this->logger->warning('sw6oidc: admin session verification failed.', [
                'userId' => $userId,
                'exceptionClass' => $exception::class,
                'exception' => $exception->getMessage(),
            ]);

            return $this->json(['error' => 'invalid_grant', 'error_description' => $exception->getMessage()], 400);
        }

        $this->logger->debug('sw6oidc: admin session verification succeeded, skipping password reconfirmation.', ['userId' => $userId]);

        return (new HttpFoundationFactory())->createResponse($tokenResponse);
    }

    private function isSsoProvisioned(string $userId, Context $context): bool
    {
        if ($this->bindingService->getBoundProviderId(Sw6OidcUserProviderEntity::USER_TYPE_ADMIN, $userId, $context) !== null) {
            return true;
        }

        return $this->passkeyCredentialRepository->findAllForOwner('admin', $userId, $context) !== [];
    }

    /**
     * @param array<string, string> $query
     */
    private function administrationLoginUrl(array $query): string
    {
        return rtrim($this->administrationBaseUrl, '/') . '/#/login?' . http_build_query($query);
    }
}
