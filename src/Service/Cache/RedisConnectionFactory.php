<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Cache;

use Psr\Log\LoggerInterface;

/**
 * Opens a dedicated phpredis connection for atomic one-time-token consumption
 * (GETDEL), independent of whatever cache backend Shopware itself is configured
 * with — mirroring the Magento module's RedisConnectionFactory/RedisAtomicCache.
 *
 * Configured via the `SW6OIDC_REDIS_DSN` environment variable (e.g.
 * redis://[:password@]host:port[/db]). Deliberately a plugin-owned setting rather
 * than introspecting Shopware's own cache config, since Shopware has no single
 * well-known "the cache backend is Redis at this DSN" parameter to read the way
 * Magento's env.php does — leaving it unset is a fully supported, safe default
 * that simply keeps atomic tokens on the CachePoolAtomicCache fallback.
 */
class RedisConnectionFactory
{
    public function __construct(
        private readonly ?string $dsn,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function create(): ?\Redis
    {
        if (!$this->dsn || !\extension_loaded('redis')) {
            return null;
        }

        $parts = parse_url($this->dsn);

        if ($parts === false || !isset($parts['host'])) {
            $this->logger->warning('sw6oidc: SW6OIDC_REDIS_DSN is set but could not be parsed; falling back to the cache-pool atomic cache.');

            return null;
        }

        try {
            $redis = new \Redis();
            $redis->connect($parts['host'], $parts['port'] ?? 6379, 1.5);

            if (isset($parts['pass'])) {
                $redis->auth($parts['pass']);
            }

            $database = isset($parts['path']) ? (int) ltrim($parts['path'], '/') : 0;

            if ($database > 0) {
                $redis->select($database);
            }

            return $redis;
        } catch (\Throwable $exception) {
            $this->logger->warning('sw6oidc: could not open the dedicated atomic-cache Redis connection; falling back to the cache-pool atomic cache.', [
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
