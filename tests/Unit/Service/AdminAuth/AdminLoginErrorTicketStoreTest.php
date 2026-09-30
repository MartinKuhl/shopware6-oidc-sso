<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\AdminAuth;

use MartinKuhl\Sw6Oidc\Service\AdminAuth\AdminLoginErrorTicketStore;
use MartinKuhl\Sw6Oidc\Tests\Unit\Support\InMemoryAtomicCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdminLoginErrorTicketStore::class)]
final class AdminLoginErrorTicketStoreTest extends TestCase
{
    public function testTicketIsRedeemableExactlyOnce(): void
    {
        $store = new AdminLoginErrorTicketStore(new InMemoryAtomicCache());
        $ticket = $store->create('Staff only.');

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', $ticket);
        self::assertSame('Staff only.', $store->redeem($ticket));
        self::assertNull($store->redeem($ticket));
    }

    public function testMalformedTicketsAreRejectedWithoutACacheLookup(): void
    {
        $cache = new InMemoryAtomicCache();
        $cache->items['sw6oidc_admin_login_error_short'] = 'x';
        $store = new AdminLoginErrorTicketStore($cache);

        self::assertNull($store->redeem(null));
        self::assertNull($store->redeem('short'));
        self::assertNull($store->redeem('../../etc/passwd-aaaaaaaaaaaaaaaa'));
        self::assertSame('x', $cache->items['sw6oidc_admin_login_error_short']);
    }
}
