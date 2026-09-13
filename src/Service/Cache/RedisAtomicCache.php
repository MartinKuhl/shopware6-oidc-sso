<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Cache;

/**
 * True atomic read-and-delete via Redis GETDEL (falls back to a GET+DEL Lua script
 * on older Redis servers without GETDEL, Redis < 6.2). Use this instead of
 * CachePoolAtomicCache for multi-node HA deployments — see the plan's High
 * Availability note, mirroring the Magento module's Redis requirement for shared
 * OIDC state across web nodes.
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

    public function __construct(
        private readonly \Redis $redis,
        private readonly AtomicCacheInterface $fallback,
    ) {
    }

    public function save(string $key, string $value, int $ttlSeconds): void
    {
        $this->redis->setex($this->prefixedKey($key), $ttlSeconds, $value);
    }

    public function getAndDelete(string $key): ?string
    {
        $redisKey = $this->prefixedKey($key);

        try {
            // PHPStan's phpredis stub always declares getdel() (added in
            // phpredis ~5.3.0 / Redis 6.2), so it can't see that this
            // actually varies across the range of phpredis versions this
            // plugin supports at runtime - the check itself is real and
            // needed, not dead code.
            if (method_exists($this->redis, 'getdel')) { // @phpstan-ignore function.alreadyNarrowedType
                $value = $this->redis->getdel($redisKey);
            } else {
                $value = $this->redis->eval(self::GET_AND_DELETE_LUA, [$redisKey], 1);
            }
        } catch (\Throwable) {
            // Connection dropped mid-request: degrade to the non-atomic fallback
            // rather than failing the login flow outright.
            return $this->fallback->getAndDelete($key);
        }

        return \is_string($value) ? $value : null;
    }

    private function prefixedKey(string $key): string
    {
        return 'sw6oidc:' . hash('sha256', $key);
    }
}
