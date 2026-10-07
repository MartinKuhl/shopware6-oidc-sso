<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\AdminAuth;

use League\OAuth2\Server\CryptKeyInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Api\OAuth\AccessTokenRepository;
use Shopware\Core\Framework\Api\OAuth\ClientRepository;
use Shopware\Core\Framework\Api\OAuth\FakeCryptKey;
use Shopware\Core\Framework\Api\OAuth\RefreshTokenRepository;
use Shopware\Core\Framework\Api\OAuth\Scope\UserVerifiedScope;
use Shopware\Core\Framework\Api\OAuth\ScopeRepository;
use Shopware\Core\Framework\Api\OAuth\User\User;
use Shopware\Core\Framework\Api\OAuth\UserRepository;

/**
 * The admin bridge builds a second League AuthorizationServer on Shopware
 * core's OAuth services, several of them `@internal` or
 * `#[BecomesInternal('v6.8.0')]` (R3-L49). The plugin's own code only types
 * against League interfaces; this test pins everything it still needs from
 * core, so a Shopware update that moves one fails here, not at a login.
 */
#[CoversNothing]
final class CoreOAuthContractTest extends TestCase
{
    public function testTheCoreServicesStillImplementTheLeagueInterfaces(): void
    {
        self::assertTrue(is_a(ClientRepository::class, ClientRepositoryInterface::class, true));
        self::assertTrue(is_a(AccessTokenRepository::class, AccessTokenRepositoryInterface::class, true));
        self::assertTrue(is_a(ScopeRepository::class, ScopeRepositoryInterface::class, true));
        self::assertTrue(is_a(RefreshTokenRepository::class, RefreshTokenRepositoryInterface::class, true));
        self::assertTrue(is_a(UserRepository::class, UserRepositoryInterface::class, true));
        self::assertTrue(is_a(FakeCryptKey::class, CryptKeyInterface::class, true));
    }

    public function testTheCoreSpecificsTheBridgeUsesStillExist(): void
    {
        // Sw6OidcSessionDestructionService, PasswordSessionRevoker
        self::assertTrue(method_exists(RefreshTokenRepository::class, 'revokeRefreshTokensForUser'));
        // AdminOidcGrant: finalizes scopes exactly like a password login, issues core's user
        self::assertTrue(\defined(ScopeRepository::class . '::PASSWORD_GRANT'));
        self::assertTrue(class_exists(User::class));
        // UserVerifiedScope (the step-up scope)
        self::assertSame('user-verified', UserVerifiedScope::IDENTIFIER);
    }
}
