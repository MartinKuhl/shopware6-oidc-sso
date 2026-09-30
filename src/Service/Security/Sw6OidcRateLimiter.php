<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Security;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Per-client-IP rate limiting for the plugin's unauthenticated endpoints
 * (Back-/Front-Channel Logout, OIDC callbacks). Built directly on Symfony's
 * RateLimiterFactory — a plugin cannot register limiters into Shopware core's
 * own `shopware.api.rate_limiter` registry.
 *
 * Penalty model: only **failed** requests consume the budget
 * (recordFailure()); isBlocked() just peeks. A busy IdP pushing many valid
 * logout tokens from one address, or an office behind one NAT address
 * logging in, is never throttled — only an address producing a stream of
 * invalid requests (forged tokens, replayed state, garbage) is. Fixed
 * window, 10 failures per 60 s per scope and address.
 *
 * Storage is Shopware's `cache.rate_limiter` pool when present (shared across
 * nodes in a cluster setup), else `cache.app`. No lock: a race can let a few
 * extra failures through, which is fine for this purpose.
 */
class Sw6OidcRateLimiter
{
    public const SCOPE_BACKCHANNEL_LOGOUT = 'backchannel_logout';
    public const SCOPE_FRONTCHANNEL_LOGOUT = 'frontchannel_logout';
    public const SCOPE_CALLBACK = 'callback';

    private readonly RateLimiterFactory $factory;

    public function __construct(
        ?CacheItemPoolInterface $rateLimiterPool,
        CacheItemPoolInterface $fallbackPool,
        int $limit = 10,
        int $intervalSeconds = 60,
    ) {
        $this->factory = new RateLimiterFactory(
            ['id' => 'sw6oidc', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => $intervalSeconds . ' seconds'],
            new CacheStorage($rateLimiterPool ?? $fallbackPool),
        );
    }

    public function isBlocked(string $scope, ?string $clientIp): bool
    {
        return $this->limiter($scope, $clientIp)->consume(0)->getRemainingTokens() === 0;
    }

    public function recordFailure(string $scope, ?string $clientIp): void
    {
        $this->limiter($scope, $clientIp)->consume(1);
    }

    private function limiter(string $scope, ?string $clientIp): LimiterInterface
    {
        return $this->factory->create($scope . '-' . hash('sha256', $clientIp ?? 'unknown'));
    }
}
