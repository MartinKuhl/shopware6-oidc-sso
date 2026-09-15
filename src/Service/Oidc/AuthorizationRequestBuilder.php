<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Oidc;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Security\OidcSecurityHelper;
use Psr\Log\LoggerInterface;

/**
 * Builds the IdP authorize-endpoint redirect URL: starts a new
 * AuthorizationFlowContext (PKCE + nonce + state) and appends the standard
 * OIDC authorization-request query parameters. Shared by the Storefront and
 * Administration SP-initiated login controllers.
 */
class AuthorizationRequestBuilder
{
    public function __construct(
        private readonly OidcSecurityHelper $securityHelper,
        private readonly LoggerInterface $logger,
    ) {
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
        $authorizeUrl = $provider->getAuthorizeEndpoint() . $separator . $query;

        // Temporary diagnostic aid: this is the exact outbound redirect to
        // the IdP - if the flow never reaches our callback afterward
        // (nothing else logs, since our code never runs again until then),
        // comparing this URL against the IdP's registered client (redirect_uri
        // in particular) is usually the fastest way to tell why.
        $this->logger->debug('sw6oidc: redirecting to IdP authorize endpoint.', [
            'providerId' => $provider->getId(),
            'loginType' => $loginType,
            'authorizeUrl' => $authorizeUrl,
            'redirectUri' => $redirectUri,
        ]);

        return $authorizeUrl;
    }
}
