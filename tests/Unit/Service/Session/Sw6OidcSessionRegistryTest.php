<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Session;

use MartinKuhl\Sw6Oidc\Service\Session\Sw6OidcSession;
use MartinKuhl\Sw6Oidc\Service\Session\Sw6OidcSessionRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(Sw6OidcSessionRegistry::class)]
#[CoversClass(Sw6OidcSession::class)]
final class Sw6OidcSessionRegistryTest extends TestCase
{
    private Sw6OidcSessionRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new Sw6OidcSessionRegistry(new ArrayAdapter(), new NullLogger());
    }

    public function testRegisteredSessionResolvesBySubSidAndUser(): void
    {
        $session = $this->registry->register('p1', 'user|42', 'sid-a', 'customer', 'cust-1', 'ctx-token', 'sc-1', 'id.token.value');

        self::assertEquals([$session], $this->registry->resolve('p1', 'user|42'));
        self::assertEquals([$session], $this->registry->resolveBySid('p1', 'sid-a'));
        self::assertEquals([$session], $this->registry->resolveByUser('customer', 'cust-1'));
        self::assertSame('sc-1', $session->salesChannelId);
        self::assertSame('id.token.value', $this->registry->get($session->id)?->idToken);
    }

    public function testSubAndSidAreScopedToTheProvider(): void
    {
        $this->registry->register('p1', 'same-sub', 'same-sid', 'customer', 'cust-1', 'ctx-1');

        self::assertSame([], $this->registry->resolve('p2', 'same-sub'));
        self::assertSame([], $this->registry->resolveBySid('p2', 'same-sid'));
    }

    public function testRevokeBySidRemovesOnlyThatIdpSessionFromEveryIndex(): void
    {
        $a = $this->registry->register('p1', 'sub', 'sid-a', 'customer', 'cust-1', 'ctx-a');
        $b = $this->registry->register('p1', 'sub', 'sid-b', 'customer', 'cust-1', 'ctx-b');

        self::assertEquals([$a], $this->registry->revokeBySid('p1', 'sid-a'));

        self::assertEquals([$b], $this->registry->resolve('p1', 'sub'));
        self::assertEquals([$b], $this->registry->resolveByUser('customer', 'cust-1'));
        self::assertSame([], $this->registry->resolveBySid('p1', 'sid-a'));
        self::assertNull($this->registry->get($a->id));
    }

    public function testRevokeBySubRemovesAllOrOnlyTheGivenSid(): void
    {
        $this->registry->register('p1', 'sub', 'sid-a', 'admin', 'u1', 'jti-a');
        $b = $this->registry->register('p1', 'sub', 'sid-b', 'admin', 'u1', 'jti-b');
        $this->registry->register('p1', 'sub', null, 'admin', 'u1', 'jti-c');

        self::assertCount(1, $this->registry->revoke('p1', 'sub', 'sid-a'));
        self::assertCount(2, $this->registry->resolve('p1', 'sub'));

        $removed = $this->registry->revoke('p1', 'sub');
        self::assertCount(2, $removed);
        self::assertContainsEquals($b, $removed);
        self::assertSame([], $this->registry->resolveByUser('admin', 'u1'));
    }

    public function testEmptySidIsStoredAsNull(): void
    {
        self::assertNull($this->registry->register('p1', 'sub', '', 'customer', 'c', 'ctx')->sid);
    }

    public function testUnknownEntriesResolveToNothing(): void
    {
        self::assertNull($this->registry->get('does-not-exist'));
        self::assertSame([], $this->registry->revokeBySid('p1', 'nope'));
        self::assertSame([], $this->registry->revoke('p1', 'nope'));
    }

    public function testFromArrayRejectsIncompleteData(): void
    {
        self::assertNull(Sw6OidcSession::fromArray(['id' => 'x']));
        self::assertNull(Sw6OidcSession::fromArray(['id' => 'x', 'providerId' => 'p', 'sub' => '', 'userType' => 'admin', 'userId' => 'u', 'sessionKey' => 'k']));
    }
}
