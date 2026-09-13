<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Passkey;

use MartinKuhl\Sw6Oidc\Service\Passkey\AdminPasskeyLoginTokenTracker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(AdminPasskeyLoginTokenTracker::class)]
final class AdminPasskeyLoginTokenTrackerTest extends TestCase
{
    public function testWasUsedForReturnsTrueForARememberedMatchingPair(): void
    {
        $tracker = new AdminPasskeyLoginTokenTracker(new ArrayAdapter());

        $tracker->remember('jti-1', 'credential-1');

        self::assertTrue($tracker->wasUsedFor('jti-1', 'credential-1'));
    }

    public function testWasUsedForReturnsFalseWhenTheCredentialDoesNotMatch(): void
    {
        $tracker = new AdminPasskeyLoginTokenTracker(new ArrayAdapter());

        $tracker->remember('jti-1', 'credential-1');

        self::assertFalse($tracker->wasUsedFor('jti-1', 'some-other-credential'));
    }

    public function testWasUsedForReturnsFalseForATokenThatWasNeverRemembered(): void
    {
        $tracker = new AdminPasskeyLoginTokenTracker(new ArrayAdapter());

        self::assertFalse($tracker->wasUsedFor('never-remembered-jti', 'credential-1'));
    }

    public function testDifferentTokenIdsAreTrackedIndependently(): void
    {
        $tracker = new AdminPasskeyLoginTokenTracker(new ArrayAdapter());

        $tracker->remember('jti-1', 'credential-1');
        $tracker->remember('jti-2', 'credential-2');

        self::assertTrue($tracker->wasUsedFor('jti-1', 'credential-1'));
        self::assertTrue($tracker->wasUsedFor('jti-2', 'credential-2'));
        self::assertFalse($tracker->wasUsedFor('jti-1', 'credential-2'));
        self::assertFalse($tracker->wasUsedFor('jti-2', 'credential-1'));
    }
}
