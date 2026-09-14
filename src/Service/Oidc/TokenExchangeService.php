<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Oidc;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Http\OidcHttpClient;

/**
 * Exchanges an authorization code (+ PKCE verifier) for tokens at the provider's
 * token endpoint. Mirrors the Magento sibling module's
 * Helper/OAuth/AccessTokenRequestBody.php + Curl::sendAccessTokenRequest():
 * confidential clients authenticate via HTTP Basic (RFC 6749 §2.3.1) — the
 * de facto default `token_endpoint_auth_method` for Authelia, Keycloak, and
 * most other IdPs — with client_id/client_secret omitted from the body to
 * avoid duplicating client authentication across two mechanisms; only public
 * clients (RFC 6749 §2.1) send client_id in the body with no Authorization
 * header, since they have no secret to authenticate with.
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
        $params = array_merge([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ], $this->clientIdBodyParam($provider));

        return $this->httpClient->postForm(
            (string) $provider->getAccessTokenEndpoint(),
            $params,
            $provider->getHttpTimeout(),
            ...$this->basicAuthCredentials($provider),
        );
    }

    /**
     * @return array{access_token?: string, id_token?: string, refresh_token?: string, expires_in?: int}
     */
    public function refreshAccessToken(Sw6OidcProviderEntity $provider, string $refreshToken): array
    {
        $params = array_merge([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], $this->clientIdBodyParam($provider));

        return $this->httpClient->postForm(
            (string) $provider->getAccessTokenEndpoint(),
            $params,
            $provider->getHttpTimeout(),
            ...$this->basicAuthCredentials($provider),
        );
    }

    /**
     * Public clients have no secret to authenticate with, so the token
     * endpoint can only identify them via a client_id body parameter (RFC
     * 6749 §3.2.1). Confidential clients authenticate via the Authorization
     * header instead and must not duplicate client_id in the body.
     *
     * @return array{client_id?: string}
     */
    private function clientIdBodyParam(Sw6OidcProviderEntity $provider): array
    {
        return $provider->isPublicClient() ? ['client_id' => $provider->getClientId()] : [];
    }

    /**
     * @return array{0: string, 1: string}|array{}
     */
    private function basicAuthCredentials(Sw6OidcProviderEntity $provider): array
    {
        return $provider->isPublicClient() ? [] : [$provider->getClientId(), $provider->getClientSecret()];
    }
}
