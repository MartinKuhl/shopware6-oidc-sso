<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\AdminAuth;

/**
 * What a redeemed admin login nonce carries: the verified admin user, plus
 * the OIDC login's identity so the session registry entry can be written
 * once the minted access token's jti is known (see
 * OidcAdminAuthController::exchangeNonce()).
 */
final readonly class AdminLoginNonce
{
    public function __construct(
        public string $userId,
        public ?string $providerId = null,
        public ?string $sub = null,
        public ?string $sid = null,
        public ?string $idToken = null,
    ) {
    }
}
