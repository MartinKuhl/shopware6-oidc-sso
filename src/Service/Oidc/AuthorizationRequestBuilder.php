<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Oidc;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Security\OidcSecurityHelper;

/**
 * Builds the IdP authorize-endpoint redirect URL: starts a new
 * AuthorizationFlowContext (PKCE + nonce + state) and appends the standard
 * OIDC authorization-request query parameters. Shared by the Storefront and
 * Administration SP-initiated login controllers.
 */
class AuthorizationRequestBuilder
{
    public function __construct(private readonly OidcSecurityHelper $securityHelper)
    {
    }

    public function build(
        Sw6OidcProviderEntity $provider,
        string $loginType,
        string $relayState,
        string $redirectUri,
    ): string {
        $flow = $this->securityHelper->beginAuthorizationRequest(
            $provider->getId(),
            $loginType,
            $relayState,
            $provider->getPkceFlow(),
        );

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $provider->getClientId(),
            'redirect_uri' => $redirectUri,
            'scope' => $provider->getScope(),
            'state' => $flow['state'],
            'nonce' => $flow['nonce'],
            'code_challenge' => $flow['codeChallenge'],
            'code_challenge_method' => $provider->getPkceFlow(),
        ]);

        $separator = str_contains((string) $provider->getAuthorizeEndpoint(), '?') ? '&' : '?';

        return $provider->getAuthorizeEndpoint() . $separator . $query;
    }
}
