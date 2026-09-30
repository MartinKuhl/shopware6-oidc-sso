<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Session;

use Doctrine\DBAL\Connection;
use MartinKuhl\Sw6Oidc\Service\Session\Sw6OidcSession;
use MartinKuhl\Sw6Oidc\Service\Session\Sw6OidcSessionRegistry;
use MartinKuhl\Sw6Oidc\Tests\Unit\Support\SqliteSessionRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;

#[CoversClass(Sw6OidcSessionRegistry::class)]
#[CoversClass(Sw6OidcSession::class)]
final class Sw6OidcSessionRegistryTest extends TestCase
{
    private Sw6OidcSessionRegistry $registry;

    private Connection $connection;

    private string $providerId;

    private string $customerId;

    protected function setUp(): void
    {
        $this->connection = SqliteSessionRegistry::connection();
        $this->registry = SqliteSessionRegistry::create($this->connection);
        $this->providerId = Uuid::randomHex();
        $this->customerId = Uuid::randomHex();
    }

    public function testRegisteredSessionResolvesBySubSidAndUser(): void
    {
        $salesChannelId = Uuid::randomHex();
        $session = $this->registry->register($this->providerId, 'user|42', 'sid-a', 'customer', $this->customerId, 'ctx-token', $salesChannelId, 'id.token.value', 'at', 'rt');

        self::assertEquals([$session], $this->registry->resolve($this->providerId, 'user|42'));
        self::assertEquals([$session], $this->registry->resolveBySid($this->providerId, 'sid-a'));
        self::assertEquals([$session], $this->registry->resolveByUser('customer', $this->customerId));
        self::assertEquals($session, $this->registry->findBySessionKey('customer', $this->customerId, 'ctx-token'));

        $stored = $this->registry->get($session->id);
        self::assertSame($salesChannelId, $stored?->salesChannelId);
        self::assertSame('id.token.value', $stored?->idToken);
        self::assertSame('at', $stored?->idpAccessToken);
        self::assertSame('rt', $stored?->idpRefreshToken);
    }

    public function testCredentialsAreNeverStoredInPlaintext(): void
    {
        $this->registry->register($this->providerId, 'sub', null, 'customer', $this->customerId, 'ctx-secret-token', null, 'id.token.secret', 'access-secret');

        $row = $this->connection->fetchAssociative('SELECT * FROM `sw6oidc_session`');
        self::assertIsArray($row);
        $raw = implode("\n", array_map(static fn (mixed $value): string => \is_string($value) ? $value : '', $row));

        self::assertStringNotContainsString('ctx-secret-token', $raw);
        self::assertStringNotContainsString('id.token.secret', $raw);
        self::assertStringNotContainsString('access-secret', $raw);
        self::assertSame(hash('sha256', 'ctx-secret-token'), $row['session_key_hash']);
    }

    public function testSubAndSidAreScopedToTheProvider(): void
    {
        $this->registry->register($this->providerId, 'same-sub', 'same-sid', 'customer', $this->customerId, 'ctx-1');
        $otherProvider = Uuid::randomHex();

        self::assertSame([], $this->registry->resolve($otherProvider, 'same-sub'));
        self::assertSame([], $this->registry->resolveBySid($otherProvider, 'same-sid'));
    }

    public function testRemoveEndsOnlyThatSession(): void
    {
        $a = $this->registry->register($this->providerId, 'sub', 'sid-a', 'customer', $this->customerId, 'ctx-a');
        $b = $this->registry->register($this->providerId, 'sub', 'sid-b', 'customer', $this->customerId, 'ctx-b');

        $this->registry->remove($a);

        self::assertEquals([$b], $this->registry->resolve($this->providerId, 'sub'));
        self::assertSame([], $this->registry->resolveBySid($this->providerId, 'sid-a'));
    }

    public function testRemoveAllForUser(): void
    {
        $this->registry->register($this->providerId, 'sub', 'sid-a', 'admin', $this->customerId, 'jti-a');
        $this->registry->register($this->providerId, 'sub', 'sid-b', 'admin', $this->customerId, 'jti-b');

        self::assertSame(2, $this->registry->removeAllForUser('admin', $this->customerId));
        self::assertSame([], $this->registry->resolveByUser('admin', $this->customerId));
    }

    public function testEmptySidIsStoredAsNull(): void
    {
        $session = $this->registry->register($this->providerId, 'sub', '', 'customer', $this->customerId, 'ctx');

        self::assertNull($session->sid);
        self::assertNull($this->registry->get($session->id)?->sid);
    }

    public function testExpiredEntriesAreInvisibleAndPruned(): void
    {
        $session = $this->registry->register($this->providerId, 'sub', null, 'customer', $this->customerId, 'ctx', ttlSeconds: 60);
        $this->connection->executeStatement('UPDATE `sw6oidc_session` SET `expires_at` = :past', ['past' => '2000-01-01 00:00:00.000']);

        self::assertNull($this->registry->get($session->id));
        self::assertSame([], $this->registry->resolve($this->providerId, 'sub'));
        self::assertSame(1, $this->registry->prune());
    }

    public function testPendingAdminEntryIsActivatedWithTheJti(): void
    {
        $adminId = Uuid::randomHex();
        $pending = $this->registry->register($this->providerId, 'sub', null, 'admin', $adminId, 'pending:x', ttlSeconds: 600);

        $this->registry->activate($pending->id, 'jti-1');

        self::assertSame($pending->id, $this->registry->findBySessionKey('admin', $adminId, 'jti-1')?->id);
        self::assertNull($this->registry->findBySessionKey('admin', $adminId, 'pending:x'));
        self::assertSame($pending->id, $this->registry->findForUser('admin', $adminId, $pending->id)?->id);
        self::assertNull($this->registry->findForUser('admin', Uuid::randomHex(), $pending->id), 'the handle only works for its own account');
    }

    public function testUnknownEntriesResolveToNothing(): void
    {
        self::assertNull($this->registry->get('0123456789abcdef0123456789abcdef'));
        self::assertSame([], $this->registry->resolve($this->providerId, 'nobody'));
        self::assertSame([], $this->registry->resolveByUser('admin', Uuid::randomHex()));
    }
}
