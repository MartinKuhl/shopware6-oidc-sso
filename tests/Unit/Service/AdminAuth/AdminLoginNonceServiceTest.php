<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\AdminAuth;

use MartinKuhl\Sw6Oidc\Service\AdminAuth\AdminLoginNonce;
use MartinKuhl\Sw6Oidc\Service\AdminAuth\AdminLoginNonceService;
use MartinKuhl\Sw6Oidc\Tests\Unit\Support\InMemoryAtomicCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdminLoginNonceService::class)]
#[CoversClass(AdminLoginNonce::class)]
final class AdminLoginNonceServiceTest extends TestCase
{
    public function testNonceCarriesTheOidcLoginIdentityAndIsSingleUse(): void
    {
        $service = new AdminLoginNonceService(new InMemoryAtomicCache());
        $nonce = $service->createNonce('user-1', 'provider-1', 'sub-1', 'sid-1', 'id.token');

        self::assertEquals(new AdminLoginNonce('user-1', 'provider-1', 'sub-1', 'sid-1', 'id.token'), $service->redeemNonce($nonce));
        self::assertNull($service->redeemNonce($nonce));
    }

    public function testUserIdOnlyNonce(): void
    {
        $service = new AdminLoginNonceService(new InMemoryAtomicCache());

        self::assertEquals(new AdminLoginNonce('user-1'), $service->redeemNonce($service->createNonce('user-1')));
    }

    public function testLegacyBareUserIdEntryIsStillRedeemable(): void
    {
        $cache = new InMemoryAtomicCache();
        $cache->items['sw6oidc_admin_nonce_legacy'] = '0190a1b2c3d4e5f60718293a4b5c6d7e';

        self::assertEquals(new AdminLoginNonce('0190a1b2c3d4e5f60718293a4b5c6d7e'), (new AdminLoginNonceService($cache))->redeemNonce('legacy'));
    }

    public function testUnknownOrEmptyNonce(): void
    {
        $service = new AdminLoginNonceService(new InMemoryAtomicCache());

        self::assertNull($service->redeemNonce(null));
        self::assertNull($service->redeemNonce(''));
        self::assertNull($service->redeemNonce('nope'));
    }
}
