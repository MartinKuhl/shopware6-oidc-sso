<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Controller\Api;

use League\OAuth2\Server\AuthorizationServer;
use MartinKuhl\Sw6Oidc\Service\AdminAuth\AdminLoginNonceService;
use MartinKuhl\Sw6Oidc\Service\AdminAuth\AdminOidcGrant;
use MartinKuhl\Sw6Oidc\Service\Oidc\AuthorizationRequestBuilder;
use MartinKuhl\Sw6Oidc\Service\Oidc\OidcCallbackProcessor;
use MartinKuhl\Sw6Oidc\Service\Provider\Exception\ProviderNotFoundException;
use MartinKuhl\Sw6Oidc\Service\Provider\ProviderResolver;
use MartinKuhl\Sw6Oidc\Service\Provisioning\AdminProvisioningService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    ) {
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
            $nonce = $this->loginNonceService->createNonce($adminUser->getId());

            return new RedirectResponse($this->administrationLoginUrl(['sw6oidc_nonce' => $nonce]));
        } catch (\Throwable $exception) {
            $this->logger->warning('sw6oidc: admin OIDC callback failed.', [
                'exception' => $exception->getMessage(),
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
            return $this->json(['error' => 'invalid_grant', 'error_description' => 'Unknown, expired, or already-used login nonce.'], 400);
        }

        $request->attributes->set(AdminOidcGrant::REQUEST_ATTRIBUTE_USER_ID, $userId);
        $request->request->set('grant_type', AdminOidcGrant::GRANT_IDENTIFIER);

        $psrRequest = $this->psrHttpFactory->createRequest($request);
        $psrResponse = $this->psrHttpFactory->createResponse(new Response());

        try {
            $tokenResponse = $this->adminAuthorizationServer->respondToAccessTokenRequest($psrRequest, $psrResponse);
        } catch (\League\OAuth2\Server\Exception\OAuthServerException $exception) {
            $this->logger->warning('sw6oidc: admin token exchange failed.', ['exception' => $exception->getMessage()]);

            return $this->json(['error' => 'invalid_grant', 'error_description' => $exception->getMessage()], 400);
        }

        return (new HttpFoundationFactory())->createResponse($tokenResponse);
    }

    /**
     * @param array<string, string> $query
     */
    private function administrationLoginUrl(array $query): string
    {
        return rtrim($this->administrationBaseUrl, '/') . '/#/login?' . http_build_query($query);
    }
}
