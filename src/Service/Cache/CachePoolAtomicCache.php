<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Cache;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Default AtomicCacheInterface implementation: sequential get-then-delete against
 * Shopware's own `cache.app` pool. Safe for single-node installs. Not truly atomic
 * across concurrent requests — see RedisAtomicCache for the HA-safe upgrade, which
 * mirrors the Magento module's default-vs-Redis split (no configuration required
 * for single-server deployments; Redis GETDEL is opt-in for multi-node HA).
 */
class CachePoolAtomicCache implements AtomicCacheInterface
{
    public function __construct(private readonly CacheItemPoolInterface $cache)
    {
    }

    public function save(string $key, string $value, int $ttlSeconds): void
    {
        $item = $this->cache->getItem($this->hashKey($key));
        $item->set($value);
        $item->expiresAfter($ttlSeconds);
        $this->cache->save($item);
    }

    public function getAndDelete(string $key): ?string
    {
        $cacheKey = $this->hashKey($key);
        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();
        $this->cache->deleteItem($cacheKey);

        return \is_string($value) ? $value : null;
    }

    /**
     * PSR-6 keys forbid several characters our own key prefixes use (e.g. ':'), so
     * one-time-token identifiers are hashed into a safe, fixed-length cache key.
     */
    private function hashKey(string $key): string
    {
        return 'sw6oidc_' . hash('sha256', $key);
    }
}
