<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Oidc;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Provisioning\MappedProfile;
use MartinKuhl\Sw6Oidc\Service\Security\AuthorizationFlowContext;

final readonly class OidcCallbackResult
{
    /**
     * @param array<string, mixed> $tokens raw token endpoint response (access_token, id_token, refresh_token, expires_in, ...)
     */
    public function __construct(
        public Sw6OidcProviderEntity $provider,
        public AuthorizationFlowContext $flow,
        public MappedProfile $profile,
        public array $tokens,
    ) {
    }
}
