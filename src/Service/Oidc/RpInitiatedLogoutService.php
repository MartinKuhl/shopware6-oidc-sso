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
    ) {
    }

    public function buildLogoutUrl(Sw6OidcProviderEntity $provider, ?string $idToken, string $postLogoutRedirectUri, string $statePrefix): ?string
    {
        $endSessionEndpoint = $provider->getEndSessionEndpoint();

        if ($endSessionEndpoint === null || $endSessionEndpoint === '') {
            return null;
        }

        if ($this->isAutheliaForwardAuthLogout($endSessionEndpoint)) {
            return $endSessionEndpoint . $this->querySeparator($endSessionEndpoint) . http_build_query([
                'rd' => $postLogoutRedirectUri,
            ]);
        }

        $params = [
            'post_logout_redirect_uri' => $postLogoutRedirectUri,
            'state' => $statePrefix . bin2hex(random_bytes(8)),
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
    public function revokeToken(Sw6OidcProviderEntity $provider, ?string $accessToken): void
    {
        if ($accessToken === null || $accessToken === '') {
            return;
        }

        $revocationEndpoint = $provider->getRevocationEndpoint();

        if ($revocationEndpoint === null || $revocationEndpoint === '') {
            return;
        }

        $params = [
            'token' => $accessToken,
            'client_id' => $provider->getClientId(),
        ];

        if (!$provider->isPublicClient()) {
            $params['client_secret'] = $provider->getClientSecret();
        }

        try {
            $this->httpClient->postForm($revocationEndpoint, $params, $provider->getHttpTimeout());
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
