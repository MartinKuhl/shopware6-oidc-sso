<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Cache;

use Psr\Log\LoggerInterface;

/**
 * The AtomicCacheInterface implementation the plugin always wires. Selects its
 * backend at runtime rather than at container-compile time (a compile-time
 * switch would be frozen into Shopware's cached container, so changing
 * SW6OIDC_REDIS_DSN would silently do nothing until the next cache:clear):
 *
 *  - SW6OIDC_REDIS_DSN set, ext-redis loaded, connection OK: true atomic
 *    read-and-delete via Redis GETDEL (or a GET+DEL Lua script on Redis < 6.2).
 *    Required for multi-node HA — mirrors the Magento module's Redis
 *    requirement for shared OIDC state across web nodes.
 *  - Otherwise: every call delegates to the fallback (CachePoolAtomicCache,
 *    sequential get-then-delete on cache.app, single-node safe).
 *
 * The connection is opened lazily, once per process. A value written to the
 * fallback because Redis failed on save() is still found by getAndDelete(),
 * which consults the fallback on a Redis miss or error.
 */
class RedisAtomicCache implements AtomicCacheInterface
{
    private const GET_AND_DELETE_LUA = <<<'LUA'
        local value = redis.call('GET', KEYS[1])
        if value then
            redis.call('DEL', KEYS[1])
        end
        return value
        LUA;

    private ?\Redis $redis = null;

    private bool $resolved = false;

    public function __construct(
        private readonly RedisConnectionFactory $connectionFactory,
        private readonly AtomicCacheInterface $fallback,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function save(string $key, string $value, int $ttlSeconds): void
    {
        $redis = $this->redis();

        if (!$redis instanceof \Redis) {
            $this->fallback->save($key, $value, $ttlSeconds);

            return;
        }

        try {
            $redis->setex($this->prefixedKey($key), $ttlSeconds, $value);
        } catch (\Throwable $exception) {
            $this->logger->warning('sw6oidc: Redis save failed; using the non-atomic cache-pool fallback for this token.', [
                'exception' => $exception->getMessage(),
            ]);
            $this->fallback->save($key, $value, $ttlSeconds);
        }
    }

    public function getAndDelete(string $key): ?string
    {
        $redis = $this->redis();

        if (!$redis instanceof \Redis) {
            return $this->fallback->getAndDelete($key);
        }

        $redisKey = $this->prefixedKey($key);

        try {
            // PHPStan's phpredis stub always declares getdel() (added in
            // phpredis ~5.3.0 / Redis 6.2), so it can't see that this
            // actually varies across the range of phpredis versions this
            // plugin supports at runtime - the check itself is real and
            // needed, not dead code.
            if (method_exists($redis, 'getdel')) { // @phpstan-ignore function.alreadyNarrowedType
                $value = $redis->getdel($redisKey);
            } else {
                $value = $redis->eval(self::GET_AND_DELETE_LUA, [$redisKey], 1);
            }
        } catch (\Throwable $exception) {
            // Connection dropped mid-request: degrade to the non-atomic fallback
            // rather than failing the login flow outright.
            $this->logger->warning('sw6oidc: Redis getAndDelete failed; trying the cache-pool fallback.', [
                'exception' => $exception->getMessage(),
            ]);

            return $this->fallback->getAndDelete($key);
        }

        if (\is_string($value)) {
            return $value;
        }

        // Miss: the token may have been written to the fallback by a save()
        // that hit a Redis error.
        return $this->fallback->getAndDelete($key);
    }

    private function redis(): ?\Redis
    {
        if (!$this->resolved) {
            $this->resolved = true;
            $this->redis = $this->connectionFactory->create();
        }

        return $this->redis;
    }

    private function prefixedKey(string $key): string
    {
        return 'sw6oidc:' . hash('sha256', $key);
    }
}
