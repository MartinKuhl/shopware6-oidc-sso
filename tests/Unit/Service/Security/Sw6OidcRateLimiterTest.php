<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Security;

use MartinKuhl\Sw6Oidc\Service\Security\Sw6OidcRateLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(Sw6OidcRateLimiter::class)]
final class Sw6OidcRateLimiterTest extends TestCase
{
    public function testOnlyFailuresConsumeTheBudget(): void
    {
        $limiter = new Sw6OidcRateLimiter(null, new ArrayAdapter(), 3);

        for ($i = 0; $i < 50; ++$i) {
            self::assertFalse($limiter->isBlocked('scope', '198.51.100.1'), 'peeking never consumes');
        }

        $limiter->recordFailure('scope', '198.51.100.1');
        $limiter->recordFailure('scope', '198.51.100.1');
        self::assertFalse($limiter->isBlocked('scope', '198.51.100.1'));

        $limiter->recordFailure('scope', '198.51.100.1');
        self::assertTrue($limiter->isBlocked('scope', '198.51.100.1'));
    }

    public function testBudgetsArePerScopeAndAddress(): void
    {
        $limiter = new Sw6OidcRateLimiter(null, new ArrayAdapter(), 1);
        $limiter->recordFailure('scope-a', '198.51.100.1');

        self::assertTrue($limiter->isBlocked('scope-a', '198.51.100.1'));
        self::assertFalse($limiter->isBlocked('scope-b', '198.51.100.1'));
        self::assertFalse($limiter->isBlocked('scope-a', '198.51.100.2'));
    }

    public function testPrefersTheDedicatedPool(): void
    {
        $dedicated = new ArrayAdapter();
        $fallback = new ArrayAdapter();
        (new Sw6OidcRateLimiter($dedicated, $fallback, 1))->recordFailure('scope', null);

        self::assertNotSame([], $dedicated->getValues());
        self::assertSame([], $fallback->getValues());
    }
}
