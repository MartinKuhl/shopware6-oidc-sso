<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Oidc;

use MartinKuhl\Sw6Oidc\Service\Http\OidcHttpClient;

/**
 * Fetches a provider's `.well-known/openid-configuration` discovery document and
 * maps it to our provider entity's endpoint field names — used by the admin
 * Provider save flow to auto-populate endpoints, mirroring the Magento module's
 * auto-discovery-on-save behavior.
 */
class OidcDiscoveryService
{
    private const DEFAULT_TIMEOUT_SECONDS = 10;

    public function __construct(private readonly OidcHttpClient $httpClient)
    {
    }

    /**
     * @return array{
     *     authorizeEndpoint?: string,
     *     accessTokenEndpoint?: string,
     *     userInfoEndpoint?: string,
     *     endSessionEndpoint?: string,
     *     revocationEndpoint?: string,
     *     jwksEndpoint?: string,
     *     issuer?: string,
     * }
     */
    public function discover(string $wellKnownConfigUrl, int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): array
    {
        $document = $this->httpClient->getJson($wellKnownConfigUrl, $timeoutSeconds);

        $map = [
            'authorization_endpoint' => 'authorizeEndpoint',
            'token_endpoint' => 'accessTokenEndpoint',
            'userinfo_endpoint' => 'userInfoEndpoint',
            'end_session_endpoint' => 'endSessionEndpoint',
            'revocation_endpoint' => 'revocationEndpoint',
            'jwks_uri' => 'jwksEndpoint',
            'issuer' => 'issuer',
        ];

        $result = [];

        foreach ($map as $documentKey => $fieldName) {
            if (isset($document[$documentKey]) && \is_string($document[$documentKey])) {
                $result[$fieldName] = $document[$documentKey];
            }
        }

        return $result;
    }
}
