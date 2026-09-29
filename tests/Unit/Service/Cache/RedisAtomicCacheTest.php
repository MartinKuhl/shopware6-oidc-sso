<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Cache;

use MartinKuhl\Sw6Oidc\Service\Cache\RedisAtomicCache;
use MartinKuhl\Sw6Oidc\Service\Cache\RedisConnectionFactory;
use MartinKuhl\Sw6Oidc\Tests\Unit\Support\InMemoryAtomicCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(RedisAtomicCache::class)]
#[RequiresPhpExtension('redis')]
final class RedisAtomicCacheTest extends TestCase
{
    public function testWithoutConnectionEverythingGoesToFallback(): void
    {
        $fallback = new InMemoryAtomicCache();
        $cache = new RedisAtomicCache(new RedisConnectionFactory(null, new NullLogger()), $fallback, new NullLogger());

        $cache->save('k', 'v', 60);

        self::assertSame(['k' => 'v'], $fallback->items);
        self::assertSame('v', $cache->getAndDelete('k'));
        self::assertNull($cache->getAndDelete('k'));
    }

    public function testUsesRedisGetdelWhenConnected(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())->method('setex')->with(self::stringStartsWith('sw6oidc:'), 60, 'v');
        $redis->expects(self::once())->method('getdel')->willReturn('v');
        $fallback = new InMemoryAtomicCache();

        $cache = new RedisAtomicCache($this->factoryReturning($redis), $fallback, new NullLogger());
        $cache->save('k', 'v', 60);

        self::assertSame('v', $cache->getAndDelete('k'));
        self::assertSame([], $fallback->items);
    }

    public function testSaveErrorFallsBackAndIsStillConsumable(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('setex')->willThrowException(new \RedisException('gone'));
        $redis->method('getdel')->willReturn(false);
        $fallback = new InMemoryAtomicCache();

        $cache = new RedisAtomicCache($this->factoryReturning($redis), $fallback, new NullLogger());
        $cache->save('k', 'v', 60);

        self::assertSame('v', $cache->getAndDelete('k'));
        self::assertNull($cache->getAndDelete('k'));
    }

    public function testReadErrorFallsBack(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('getdel')->willThrowException(new \RedisException('gone'));
        $fallback = new InMemoryAtomicCache();
        $fallback->save('k', 'v', 60);

        $cache = new RedisAtomicCache($this->factoryReturning($redis), $fallback, new NullLogger());

        self::assertSame('v', $cache->getAndDelete('k'));
    }

    public function testConnectsOnlyOnce(): void
    {
        $factory = $this->createMock(RedisConnectionFactory::class);
        $factory->expects(self::once())->method('create')->willReturn(null);

        $cache = new RedisAtomicCache($factory, new InMemoryAtomicCache(), new NullLogger());
        $cache->save('a', '1', 60);
        $cache->getAndDelete('a');
        $cache->getAndDelete('b');
    }

    private function factoryReturning(\Redis $redis): RedisConnectionFactory
    {
        $factory = $this->createMock(RedisConnectionFactory::class);
        $factory->method('create')->willReturn($redis);

        return $factory;
    }
}
