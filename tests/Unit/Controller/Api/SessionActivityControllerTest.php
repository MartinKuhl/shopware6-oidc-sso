<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Controller\Api;

use MartinKuhl\Sw6Oidc\Controller\Api\SessionActivityController;
use MartinKuhl\Sw6Oidc\Service\Session\Sw6OidcSession;
use MartinKuhl\Sw6Oidc\Service\Session\Sw6OidcSessionActivityRecorder;
use MartinKuhl\Sw6Oidc\Service\Session\Sw6OidcSessionDestructionService;
use MartinKuhl\Sw6Oidc\Service\Session\Sw6OidcSessionRegistry;
use MartinKuhl\Sw6Oidc\Tests\Unit\Support\InMemorySessionActivityRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(SessionActivityController::class)]
final class SessionActivityControllerTest extends TestCase
{
    private InMemorySessionActivityRepository $store;

    private Sw6OidcSessionActivityRecorder $recorder;

    private Sw6OidcSessionRegistry $registry;

    private Sw6OidcSessionDestructionService&MockObject $destruction;

    protected function setUp(): void
    {
        $this->store = new InMemorySessionActivityRepository();
        $this->recorder = new Sw6OidcSessionActivityRecorder($this->store->mock(fn (string $class) => $this->createMock($class)), new NullLogger());
        $this->registry = new Sw6OidcSessionRegistry(new ArrayAdapter(), new NullLogger());
        $this->destruction = $this->createMock(Sw6OidcSessionDestructionService::class);
    }

    public function testRegisteredCustomerSessionIsEndedExactly(): void
    {
        $session = $this->registry->register('p1', 'sub', 'sid', 'customer', 'c1', 'ctx-a', 'sc');
        $this->recorder->recordLogin('customer', 'c1', 'oidc', 'ctx-a', null, 'p1', $session);
        $this->recorder->recordLogin('customer', 'c1', 'passkey', 'ctx-b', null);

        $this->destruction->expects(self::once())->method('destroy')->with($session);
        $this->destruction->expects(self::never())->method('destroyAllForUser');

        $body = $this->forceLogout($this->activityIdFor('ctx-a'));

        self::assertSame(['alreadyLoggedOut' => false, 'endedAllSessions' => false], $body);
        self::assertNull($this->registry->get($session->id));
        self::assertSame(['forced', null], $this->reasons());
    }

    public function testPasskeySessionEndsAllSessionsOfTheAccount(): void
    {
        $this->recorder->recordLogin('customer', 'c1', 'passkey', 'ctx-a', null);
        $this->recorder->recordLogin('customer', 'c1', 'oidc', 'ctx-b', null);

        $this->destruction->expects(self::once())->method('destroyAllForUser')->with('customer', 'c1');

        self::assertTrue($this->forceLogout($this->activityIdFor('ctx-a'))['endedAllSessions']);
        self::assertSame(['forced', 'forced'], $this->reasons());
    }

    public function testAdminAlwaysEndsAllSessionsAndClearsTheirRegistryEntries(): void
    {
        $session = $this->registry->register('p1', 'sub', 'sid', 'admin', 'u1', 'jti-a');
        $this->recorder->recordLogin('admin', 'u1', 'oidc', 'jti-a', null, 'p1', $session);

        $this->destruction->expects(self::once())->method('destroyAllForUser')->with('admin', 'u1');

        self::assertTrue($this->forceLogout($this->activityIdFor('jti-a'))['endedAllSessions']);
        self::assertSame([], $this->registry->resolveByUser('admin', 'u1'));
    }

    public function testClosedOrUnknownActivity(): void
    {
        $this->recorder->recordLogin('customer', 'c1', 'passkey', 'ctx-a', null);
        $this->recorder->recordLogout('customer', 'c1', 'logout', 'ctx-a');
        $this->destruction->expects(self::never())->method(self::anything());

        self::assertTrue($this->forceLogout($this->activityIdFor('ctx-a'))['alreadyLoggedOut']);
        self::assertSame(404, $this->controller()->forceLogout('0190a1b2c3d4e5f60718293a4b5c6d7e')->getStatusCode());
    }

    /**
     * @return array<string, mixed>
     */
    private function forceLogout(string $activityId): array
    {
        return json_decode((string) $this->controller()->forceLogout($activityId)->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function controller(): SessionActivityController
    {
        return new SessionActivityController($this->recorder, $this->registry, $this->destruction, new NullLogger());
    }

    private function activityIdFor(string $sessionKey): string
    {
        foreach ($this->store->rows as $id => $row) {
            if ($row['sessionKeyHash'] === hash('sha256', $sessionKey)) {
                return (string) $id;
            }
        }

        self::fail('no activity for ' . $sessionKey);
    }

    /**
     * @return list<string|null> logout reasons in insertion order
     */
    private function reasons(): array
    {
        return array_values(array_map(static fn (array $row): ?string => $row['logoutReason'] ?? null, $this->store->rows));
    }
}
