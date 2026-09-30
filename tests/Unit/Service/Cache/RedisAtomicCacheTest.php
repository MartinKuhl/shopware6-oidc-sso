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

    public function testAlwaysUsesTheLuaScriptSoOldRedisServersWork(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::never())->method('getdel');
        $redis->expects(self::once())->method('eval')
            ->with(self::stringContains("redis.call('GET'"), self::callback(static fn (array $keys): bool => str_starts_with($keys[0], 'sw6oidc:')), 1)
            ->willReturn('v');

        $cache = new RedisAtomicCache($this->factoryReturning($redis), new InMemoryAtomicCache(), new NullLogger());

        self::assertSame('v', $cache->getAndDelete('k'));
        self::assertSame(RedisAtomicCache::BACKEND_REDIS, $cache->backend());
    }

    public function testAddIfAbsentUsesSetNx(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::exactly(2))->method('set')
            ->with(self::stringStartsWith('sw6oidc:'), '1', ['nx', 'ex' => 60])
            ->willReturnOnConsecutiveCalls(true, false);

        $cache = new RedisAtomicCache($this->factoryReturning($redis), new InMemoryAtomicCache(), new NullLogger());

        self::assertTrue($cache->addIfAbsent('jti', '1', 60));
        self::assertFalse($cache->addIfAbsent('jti', '1', 60));
    }

    public function testWithoutRedisTheBackendIsTheDatabase(): void
    {
        $cache = new RedisAtomicCache(new RedisConnectionFactory(null, new NullLogger()), new InMemoryAtomicCache(), new NullLogger());

        self::assertSame(RedisAtomicCache::BACKEND_DATABASE, $cache->backend());
        self::assertTrue($cache->addIfAbsent('k', 'v', 60));
        self::assertFalse($cache->addIfAbsent('k', 'v', 60));
    }

    public function testUsesRedisWhenConnected(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())->method('setex')->with(self::stringStartsWith('sw6oidc:'), 60, 'v');
        $redis->expects(self::once())->method('eval')->willReturn('v');
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
        $redis->method('eval')->willReturn(false);
        $fallback = new InMemoryAtomicCache();

        $cache = new RedisAtomicCache($this->factoryReturning($redis), $fallback, new NullLogger());
        $cache->save('k', 'v', 60);

        self::assertSame('v', $cache->getAndDelete('k'));
        self::assertNull($cache->getAndDelete('k'));
    }

    public function testReadErrorFallsBack(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('eval')->willThrowException(new \RedisException('gone'));
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
