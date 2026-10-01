<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Oidc;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Http\OidcHttpClient;
use Psr\Log\LoggerInterface;

/**
 * Shared RP-Initiated Logout (OIDC RP-Initiated Logout + RFC 7009 revocation),
 * session-agnostic so both the Storefront customer logout subscriber and the
 * Administration logout listener can use it — mirrors the Magento module's
 * Model/Service/RpInitiatedLogoutService.php, including its Authelia
 * forward-auth detection (in scope for Phase 1 per the plan).
 */
class RpInitiatedLogoutService
{
    public function __construct(
        private readonly OidcHttpClient $httpClient,
        private readonly LoggerInterface $logger,
        private readonly PostLogoutState $postLogoutState,
    ) {
    }

    /**
     * @param string $defaultPostLogoutRedirectUri used unless the provider sets its own `post_logout_url`
     * @param PostLogoutState::TARGET_* $target which login page the shared `/sw6oidc/postlogout` landing picks
     */
    public function buildLogoutUrl(Sw6OidcProviderEntity $provider, ?string $idToken, string $defaultPostLogoutRedirectUri, string $target): ?string
    {
        $endSessionEndpoint = $provider->getEndSessionEndpoint();

        if ($endSessionEndpoint === null || $endSessionEndpoint === '') {
            return null;
        }

        $override = $provider->getPostLogoutUrl();
        $postLogoutRedirectUri = $override !== null && $override !== '' ? $override : $defaultPostLogoutRedirectUri;
        $state = $this->postLogoutState->create($target);

        if ($this->isAutheliaForwardAuthLogout($endSessionEndpoint)) {
            // `rd` is followed verbatim, so the shared landing gets its state as a query parameter.
            if (str_ends_with((string) parse_url($postLogoutRedirectUri, PHP_URL_PATH), '/sw6oidc/postlogout')) {
                $postLogoutRedirectUri .= $this->querySeparator($postLogoutRedirectUri) . http_build_query(['state' => $state]);
            }

            return $endSessionEndpoint . $this->querySeparator($endSessionEndpoint) . http_build_query([
                'rd' => $postLogoutRedirectUri,
            ]);
        }

        $params = [
            'post_logout_redirect_uri' => $postLogoutRedirectUri,
            'state' => $state,
        ];

        if ($idToken !== null) {
            $params['id_token_hint'] = $idToken;
        }

        return $endSessionEndpoint . $this->querySeparator($endSessionEndpoint) . http_build_query($params);
    }

    /**
     * Fire-and-forget: a failed revocation must never block the user from
     * logging out.
     */
    /**
     * RFC 7009 revocation of the login's IdP tokens (refresh token first —
     * revoking it usually invalidates the access token too). Fire-and-forget.
     */
    public function revokeTokens(Sw6OidcProviderEntity $provider, LogoutContext $logoutContext): void
    {
        $this->revokeToken($provider, $logoutContext->idpRefreshToken, 'refresh_token');
        $this->revokeToken($provider, $logoutContext->idpAccessToken, 'access_token');
    }

    public function revokeToken(Sw6OidcProviderEntity $provider, ?string $token, string $tokenTypeHint = 'access_token'): void
    {
        if ($token === null || $token === '') {
            return;
        }

        $revocationEndpoint = $provider->getRevocationEndpoint();

        if ($revocationEndpoint === null || $revocationEndpoint === '') {
            return;
        }

        // Same client-authentication convention as TokenExchangeService: a
        // confidential client authenticates via HTTP Basic and omits
        // client_id from the body; a public client has no secret, so it
        // identifies itself via client_id in the body instead (RFC 7009 §2.1
        // references the token endpoint's authentication methods).
        $params = ['token' => $token, 'token_type_hint' => $tokenTypeHint];

        if ($provider->isPublicClient()) {
            $params['client_id'] = $provider->getClientId();
        }

        $secret = $provider->isPublicClient() ? null : $provider->getUsableClientSecret();

        if (!$provider->isPublicClient() && $secret === null) {
            // Undecryptable secret: never send the envelope to the IdP (N-L5).
            $this->logger->warning('sw6oidc: RFC 7009 revocation skipped, the client secret is unavailable.', ['providerId' => $provider->getId()]);

            return;
        }

        try {
            $this->httpClient->postForm(
                $revocationEndpoint,
                $params,
                $provider->getHttpTimeout(),
                $provider->isPublicClient() ? null : $provider->getClientId(),
                $secret,
            );
        } catch (\Throwable $exception) {
            $this->logger->warning('sw6oidc: RFC 7009 token revocation failed (non-fatal).', [
                'providerId' => $provider->getId(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Authelia's forward-auth logout endpoint path ends in `/logout` (not
     * `/oauth2/...` or `/oidc/...`) and takes `?rd=<returnUrl>` instead of the
     * standard OIDC RP-Initiated Logout parameters.
     */
    private function isAutheliaForwardAuthLogout(string $endSessionEndpoint): bool
    {
        $path = parse_url($endSessionEndpoint, PHP_URL_PATH) ?? '';

        return str_ends_with($path, '/logout') && !str_contains($path, '/oauth2/') && !str_contains($path, '/oidc/');
    }

    private function querySeparator(string $url): string
    {
        return str_contains($url, '?') ? '&' : '?';
    }
}
