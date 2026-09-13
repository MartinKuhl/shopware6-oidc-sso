<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Oidc;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Http\OidcHttpClient;

/**
 * Exchanges an authorization code (+ PKCE verifier) for tokens at the provider's
 * token endpoint. Mirrors Helper/OAuth/AccessTokenRequest.php: omits
 * client_secret entirely for public clients (RFC 6749 §2.1), always includes the
 * PKCE code_verifier.
 */
class TokenExchangeService
{
    public function __construct(private readonly OidcHttpClient $httpClient)
    {
    }

    /**
     * The response shape below is only the happy-path expectation, never a
     * guarantee - this is raw decoded JSON from a third-party IdP's token
     * endpoint (see OidcHttpClient::postForm()'s own untyped
     * array<string, mixed> return), so every field including access_token
     * is genuinely optional as far as the type system is concerned; callers
     * must keep validating presence before use.
     *
     * @return array{access_token?: string, id_token?: string, refresh_token?: string, expires_in?: int}
     */
    public function exchangeCodeForTokens(
        Sw6OidcProviderEntity $provider,
        string $code,
        string $redirectUri,
        string $codeVerifier,
    ): array {
        $params = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $provider->getClientId(),
            'code_verifier' => $codeVerifier,
        ];

        if (!$provider->isPublicClient()) {
            $params['client_secret'] = $provider->getClientSecret();
        }

        return $this->httpClient->postForm(
            (string) $provider->getAccessTokenEndpoint(),
            $params,
            $provider->getHttpTimeout(),
        );
    }

    /**
     * @return array{access_token?: string, id_token?: string, refresh_token?: string, expires_in?: int}
     */
    public function refreshAccessToken(Sw6OidcProviderEntity $provider, string $refreshToken): array
    {
        $params = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $provider->getClientId(),
        ];

        if (!$provider->isPublicClient()) {
            $params['client_secret'] = $provider->getClientSecret();
        }

        return $this->httpClient->postForm(
            (string) $provider->getAccessTokenEndpoint(),
            $params,
            $provider->getHttpTimeout(),
        );
    }
}
