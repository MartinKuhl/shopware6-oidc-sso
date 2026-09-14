<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Controller\Api;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Http\Exception\OidcHttpException;
use MartinKuhl\Sw6Oidc\Service\Oidc\AuthorizationRequestBuilder;
use MartinKuhl\Sw6Oidc\Service\Oidc\DiscoveryUrlValidator;
use MartinKuhl\Sw6Oidc\Service\Oidc\OidcConnectionTestService;
use MartinKuhl\Sw6Oidc\Service\Oidc\OidcDiscoveryService;
use MartinKuhl\Sw6Oidc\Service\Oidc\OidcLiveLoginTestService;
use MartinKuhl\Sw6Oidc\Service\Security\Exception\InvalidStateException;
use MartinKuhl\Sw6Oidc\Service\Security\OidcSecurityHelper;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Admin-management actions for sw6oidc_provider: on-demand well-known
 * auto-discovery, a fast synchronous connection/completeness check, and a
 * full live OIDC login round-trip test — all separate from
 * OidcAdminAuthController, which is deliberately auth_required:false at the
 * class level as a pre-auth login bridge and shouldn't also carry normal
 * ACL-gated management actions.
 *
 * Every action but testCallback() requires a normal authenticated
 * Administration session (default auth_required: true) gated by the
 * sw6oidc_provider.* privileges the module already declares. testCallback()
 * is necessarily anonymous — like the real admin OIDC callback, the browser
 * returning from the IdP carries no Administration bearer token — mirroring
 * the mixed-auth pattern PasskeyAdminController already uses for its
 * login-options/login-verify actions.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class OidcProviderAdminController extends AbstractController
{
    public function __construct(
        private readonly EntityRepository $providerRepository,
        private readonly AuthorizationRequestBuilder $requestBuilder,
        private readonly OidcSecurityHelper $securityHelper,
        private readonly OidcDiscoveryService $discoveryService,
        private readonly DiscoveryUrlValidator $urlValidator,
        private readonly OidcConnectionTestService $connectionTestService,
        private readonly OidcLiveLoginTestService $liveLoginTestService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/api/_action/sw6oidc/provider/discover',
        name: 'api.action.sw6oidc.provider.discover',
        defaults: ['_acl' => ['sw6oidc_provider.editor']],
        methods: ['POST'],
    )]
    public function discover(Request $request): JsonResponse
    {
        $wellKnownConfigUrl = (string) $request->request->get('wellKnownConfigUrl', '');

        if ($wellKnownConfigUrl === '') {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'A well-known configuration URL is required.'], 400);
        }

        $timeout = (int) $request->request->get('httpTimeout', 10) ?: 10;

        $violation = $this->urlValidator->validate($wellKnownConfigUrl);

        if ($violation['blocked']) {
            return new JsonResponse(['error' => 'discovery_failed', 'message' => implode(' ', $violation['warnings'])], 400);
        }

        try {
            $endpoints = $this->discoveryService->discover($wellKnownConfigUrl, $timeout);
        } catch (OidcHttpException $exception) {
            $this->logger->warning('sw6oidc: discovery request failed.', ['exception' => $exception->getMessage()]);

            return new JsonResponse(['error' => 'discovery_failed', 'message' => $exception->getMessage()], 400);
        }

        return new JsonResponse(array_merge($endpoints, ['warnings' => $violation['warnings']]));
    }

    #[Route(
        path: '/api/_action/sw6oidc/provider/test-connection',
        name: 'api.action.sw6oidc.provider.test-connection',
        defaults: ['_acl' => ['sw6oidc_provider.editor']],
        methods: ['POST'],
    )]
    public function testConnection(Request $request): JsonResponse
    {
        $config = [
            'wellKnownConfigUrl' => $request->request->get('wellKnownConfigUrl'),
            'authorizeEndpoint' => $request->request->get('authorizeEndpoint'),
            'accessTokenEndpoint' => $request->request->get('accessTokenEndpoint'),
            'userInfoEndpoint' => $request->request->get('userInfoEndpoint'),
            'jwksEndpoint' => $request->request->get('jwksEndpoint'),
            'endSessionEndpoint' => $request->request->get('endSessionEndpoint'),
            'revocationEndpoint' => $request->request->get('revocationEndpoint'),
            'issuer' => $request->request->get('issuer'),
            'clientId' => $request->request->get('clientId'),
            'clientSecret' => $request->request->get('clientSecret'),
            'publicClient' => $request->request->getBoolean('publicClient'),
            'httpTimeout' => (int) $request->request->get('httpTimeout', 10) ?: 10,
        ];

        return new JsonResponse($this->connectionTestService->test($config));
    }

    #[Route(
        path: '/api/_action/sw6oidc/provider/{id}/test',
        name: 'api.action.sw6oidc.provider.test-start',
        defaults: ['_acl' => ['sw6oidc_provider.viewer']],
        methods: ['POST'],
    )]
    public function startLiveTest(string $id, Context $context): JsonResponse
    {
        $provider = $this->loadProvider($id, $context);

        if (!$provider instanceof Sw6OidcProviderEntity) {
            return new JsonResponse(['error' => 'not_found', 'message' => 'Unknown provider.'], 404);
        }

        if ($provider->getAuthorizeEndpoint() === null || $provider->getAccessTokenEndpoint() === null) {
            return new JsonResponse([
                'error' => 'incomplete_configuration',
                'message' => 'This provider is missing its authorize or access token endpoint. Run discovery or fill them in manually before testing.',
            ], 400);
        }

        $redirectUri = $this->generateUrl('api.action.sw6oidc.provider.test-callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $authorizeUrl = $this->requestBuilder->build($provider, 'test', '', $redirectUri);

        return new JsonResponse(['authorizeUrl' => $authorizeUrl]);
    }

    /**
     * The anonymous return leg from the IdP — see class docblock for why this
     * can't require auth. Consumes the single-use cached flow state exactly
     * like the real admin callback (replay-proof), runs the safe live-login
     * test pipeline, persists the outcome, and renders a small static HTML
     * result page directly (no Administration SPA boot needed, since the
     * popup that navigates here is inherently anonymous at this point anyway).
     */
    #[Route(
        path: '/api/sw6oidc/provider/test-callback',
        name: 'api.action.sw6oidc.provider.test-callback',
        defaults: ['auth_required' => false],
        methods: ['GET'],
    )]
    public function testCallback(Request $request): Response
    {
        // Shopware's CoreSubscriber computes a fresh CSP nonce for every
        // request (regardless of controller) and, depending on the shop's
        // config/packages/csp.yaml, may attach a Content-Security-Policy
        // header to this response too — without the nonce, the inline
        // <script> below would silently fail to run entirely.
        $cspNonce = $request->attributes->get(PlatformRequest::ATTRIBUTE_CSP_NONCE);
        $cspNonce = \is_string($cspNonce) ? $cspNonce : null;

        $state = $request->query->get('state');

        try {
            $flow = $this->securityHelper->consumeAuthorizationFlow(\is_string($state) ? $state : null);
        } catch (InvalidStateException $exception) {
            return $this->renderTestResultPage('fail', [
                ['id' => 'callback', 'status' => 'fail', 'detail' => $exception->getMessage()],
            ], [], $cspNonce);
        }

        if ($flow->loginType !== 'test') {
            $this->logger->warning('sw6oidc: provider test callback received a non-test authorization flow; refusing.', [
                'providerId' => $flow->providerId,
            ]);

            return $this->renderTestResultPage('fail', [
                ['id' => 'callback', 'status' => 'fail', 'detail' => 'This callback only accepts test-mode authorization flows.'],
            ], [], $cspNonce);
        }

        $context = Context::createDefaultContext();

        if ($request->query->get('error') !== null) {
            $this->logger->warning('sw6oidc: IdP returned an OAuth error on the provider test callback.', [
                'providerId' => $flow->providerId,
                'error' => $request->query->get('error'),
            ]);

            $this->persistTestStatus($flow->providerId, 'fail', $context);

            $description = $request->query->get('error_description') ?? $request->query->get('error');

            return $this->renderTestResultPage('fail', [
                ['id' => 'authorization', 'status' => 'fail', 'detail' => (string) $description],
            ], [], $cspNonce);
        }

        $code = $request->query->get('code');
        $provider = $this->loadProvider($flow->providerId, $context);

        if (!$provider instanceof Sw6OidcProviderEntity || $code === null || $code === '') {
            $this->persistTestStatus($flow->providerId, 'fail', $context);

            return $this->renderTestResultPage('fail', [
                ['id' => 'callback', 'status' => 'fail', 'detail' => 'Missing authorization code or unknown provider.'],
            ], [], $cspNonce);
        }

        $redirectUri = $this->generateUrl('api.action.sw6oidc.provider.test-callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $result = $this->liveLoginTestService->run($provider, $code, $flow->codeVerifier, $redirectUri, $flow->nonce);

        $this->persistTestStatus($provider->getId(), $result['status'], $context);

        return $this->renderTestResultPage($result['status'], $result['steps'], $result['claims'], $cspNonce);
    }

    private function loadProvider(string $id, Context $context): ?Sw6OidcProviderEntity
    {
        $provider = $this->providerRepository->search(new Criteria([$id]), $context)->first();

        return $provider instanceof Sw6OidcProviderEntity ? $provider : null;
    }

    private function persistTestStatus(string $providerId, string $status, Context $context): void
    {
        $this->providerRepository->update([[
            'id' => $providerId,
            'lastTestStatus' => $status,
            'lastTestAt' => new \DateTimeImmutable(),
        ]], $context);
    }

    /**
     * @param array<int, array{id: string, status: string, detail: string}> $steps
     * @param array<string, mixed> $claims
     */
    private function renderTestResultPage(string $status, array $steps, array $claims, ?string $cspNonce): Response
    {
        $statusLabel = ['pass' => 'TEST SUCCESSFUL', 'fail' => 'TEST FAILED'][$status] ?? strtoupper($status);
        $statusColor = $status === 'pass' ? '#2e7d32' : '#c62828';

        $stepsHtml = '';

        // A CSS class (defined in the nonce'd <style> block below), not an
        // inline style="" attribute: a CSP nonce authorizes the <style>
        // element itself but never inline style attributes, same restriction
        // as onclick="" above.
        $statusClasses = ['pass' => 'pass', 'fail' => 'fail', 'skipped' => 'skipped', 'warning' => 'warning'];

        foreach ($steps as $step) {
            $rowClass = $statusClasses[$step['status']] ?? 'fail';
            $stepsHtml .= sprintf(
                '<tr><td>%s</td><td class="status status-%s">%s</td><td>%s</td></tr>',
                $this->escapeForDisplay($step['id']),
                $rowClass,
                $this->escapeForDisplay(strtoupper($step['status'])),
                $this->escapeForDisplay($step['detail']),
            );
        }

        $claimsHtml = '';

        foreach ($claims as $key => $value) {
            $displayValue = \is_array($value) ? json_encode($value) : (string) $value;
            $claimsHtml .= sprintf(
                '<tr><td>%s</td><td>%s</td></tr>',
                $this->escapeForDisplay((string) $key),
                $this->escapeForDisplay((string) $displayValue),
            );
        }

        if ($claimsHtml === '') {
            $claimsHtml = '<tr><td colspan="2">No claims received.</td></tr>';
        }

        // JSON_HEX_* (not htmlspecialchars) is the correct escaping here: this
        // is embedded directly as a JS object literal inside <script>, whose
        // content is raw text to the HTML parser — HTML entities would not be
        // decoded and would break the literal instead of protecting it.
        $payloadJson = json_encode(
            ['type' => 'sw6oidc-test-result', 'status' => $status, 'steps' => $steps],
            \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT,
        ) ?: '{}';

        // Required for the inline <script>/<style> below to run at all: this
        // route's _routeScope is 'api', whose CSP template Shopware applies
        // is `script-src 'none'` outright (no %nonce% token to substitute at
        // all, confirmed by the browser's own CSP violation report) — a
        // nonce attribute alone cannot satisfy a bare 'none' directive.
        // Instead, this response sets its OWN Content-Security-Policy header
        // scoped to exactly this nonce (see the end of this method);
        // CoreSubscriber only ever applies its header
        // `if (!$response->headers->has('Content-Security-Policy'))`, so a
        // header already present here wins. The "Close window" button
        // deliberately has no onclick="" attribute: a CSP nonce only ever
        // authorizes <script> elements, never inline event-handler
        // attributes, so the listener is attached from inside this nonced
        // script instead.
        $nonceAttr = $cspNonce !== null ? sprintf(' nonce="%s"', htmlspecialchars($cspNonce, \ENT_QUOTES)) : '';
        $scriptTag = '<script' . $nonceAttr . '>';
        $styleTag = '<style' . $nonceAttr . '>';

        $html = <<<HTML
            <!doctype html>
            <html>
            <head>
                <meta charset="utf-8">
                <title>OIDC Test Result</title>
                {$styleTag}
                    body { font-family: -apple-system, Arial, sans-serif; margin: 24px; color: #222; }
                    h1 { color: {$statusColor}; font-size: 20px; }
                    table { border-collapse: collapse; width: 100%; margin-bottom: 24px; }
                    td, th { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 13px; vertical-align: top; }
                    button { padding: 8px 16px; cursor: pointer; }
                    .status { font-weight: bold; }
                    .status-pass { color: #2e7d32; }
                    .status-fail { color: #c62828; }
                    .status-skipped { color: #757575; }
                    .status-warning { color: #b26a00; }
                </style>
            </head>
            <body>
                <h1>{$statusLabel}</h1>
                <table>
                    <thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead>
                    <tbody>{$stepsHtml}</tbody>
                </table>
                <h2>Claims received</h2>
                <table>
                    <thead><tr><th>Claim</th><th>Value</th></tr></thead>
                    <tbody>{$claimsHtml}</tbody>
                </table>
                <button id="sw6oidc-close-button" type="button">Close window</button>
                {$scriptTag}
                    (function () {
                        var payload = {$payloadJson};
                        if (window.opener && !window.opener.closed) {
                            try {
                                window.opener.postMessage(payload, window.location.origin);
                            } catch (e) { /* opener on a different origin or already gone — ignore */ }
                        }
                        document.getElementById('sw6oidc-close-button').addEventListener('click', function () {
                            window.close();
                        });
                    })();
                </script>
            </body>
            </html>
            HTML;

        $response = new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);

        // Override the api-scope's `script-src 'none'` default with a policy
        // scoped tightly to just this self-contained page: nonce'd inline
        // script/style only, nothing else allowed at all (no external
        // resources, forms, or navigation are used here). Skipped when no
        // nonce is available (should not happen in practice — CoreSubscriber
        // always sets one — but a missing nonce means we cannot construct a
        // safe policy, so fall back to leaving Shopware's own default header
        // in place rather than emitting an unenforceable or overly-loose one).
        if ($cspNonce !== null) {
            $response->headers->set('Content-Security-Policy', sprintf(
                "default-src 'none'; script-src 'nonce-%1\$s'; style-src 'nonce-%1\$s'; base-uri 'none'; form-action 'none'",
                $cspNonce,
            ));
        }

        return $response;
    }

    /**
     * HTML-escapes a value for display, then neutralizes "@" so hosting-layer
     * email obfuscation (e.g. Cloudflare's "Email Address Obfuscation" /
     * Scrape Shield) never rewrites a claim value into its masked
     * "[email protected]" placeholder + decoder-script form — the whole point
     * of this page is to show the admin the real, exact value the IdP
     * returned. Rendering "@" as a numeric character reference displays
     * identically in the browser but no longer matches a plain-text scanner
     * looking for a literal "user@domain" pattern in the response body.
     */
    private function escapeForDisplay(string $value): string
    {
        return str_replace('@', '&#64;', htmlspecialchars($value, \ENT_QUOTES));
    }
}
