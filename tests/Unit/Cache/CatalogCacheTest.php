<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cache;

use App\Cache\CatalogCache;
use App\Resilience\RedisCircuitBreaker;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

final class CatalogCacheTest extends TestCase
{
    public function testLoaderRunsOnceWhileTheEntryIsCached(): void
    {
        $cache = $this->workingCache();
        $calls = 0;
        $loader = static function () use (&$calls): string {
            ++$calls;

            return 'value';
        };

        self::assertSame('value', $cache->remember('key', ['tag'], 60, $loader));
        self::assertSame('value', $cache->remember('key', ['tag'], 60, $loader));
        self::assertSame(1, $calls);
    }

    public function testInvalidatingATagEvictsEveryEntryCarryingIt(): void
    {
        $cache = $this->workingCache();
        $version = 0;
        $loader = static function () use (&$version): int {
            return ++$version;
        };

        $cache->remember('a', ['shared', 'only-a'], 60, $loader);
        $cache->remember('b', ['shared'], 60, $loader);
        $cache->remember('c', ['unrelated'], 60, $loader);
        self::assertSame(3, $version);

        $cache->invalidate('shared');

        self::assertSame(4, $cache->remember('a', ['shared', 'only-a'], 60, $loader));
        self::assertSame(5, $cache->remember('b', ['shared'], 60, $loader));
        self::assertSame(3, $cache->remember('c', ['unrelated'], 60, $loader), 'Entries without the tag survive.');
    }

    public function testTagsCanBeDerivedFromTheLoadedValue(): void
    {
        $cache = $this->workingCache();
        $calls = 0;
        $loader = static function () use (&$calls): array {
            ++$calls;

            return ['id' => 'm-1'];
        };
        $tags = static fn (array $value): array => ['merchant.'.$value['id']];

        $cache->remember('identity', $tags, 60, $loader);
        $cache->invalidate('merchant.m-1');
        $cache->remember('identity', $tags, 60, $loader);

        self::assertSame(2, $calls);
    }

    public function testNullResultsAreCachedToo(): void
    {
        $cache = $this->workingCache();
        $calls = 0;
        $loader = static function () use (&$calls): ?string {
            ++$calls;

            return null;
        };

        self::assertNull($cache->remember('missing', [], 60, $loader));
        self::assertNull($cache->remember('missing', [], 60, $loader));
        self::assertSame(1, $calls, 'Negative caching absorbs repeated lookups of unknown keys.');
    }

    public function testLoaderFailuresPropagateAndAreNotCached(): void
    {
        $cache = $this->workingCache();
        $attempts = 0;
        $loader = static function () use (&$attempts): string {
            if (1 === ++$attempts) {
                throw new \DomainException('database is down');
            }

            return 'recovered';
        };

        try {
            $cache->remember('key', [], 60, $loader);
            self::fail('The loader exception must not be swallowed.');
        } catch (\DomainException $e) {
            self::assertSame('database is down', $e->getMessage());
        }

        self::assertSame('recovered', $cache->remember('key', [], 60, $loader));
    }

    public function testCacheFailingBeforeTheLoaderRunsDegradesToTheSourceOfTruth(): void
    {
        $cache = new CatalogCache($this->brokenPool(loadFirst: false), new NullLogger(), new RedisCircuitBreaker());
        $calls = 0;

        $value = $cache->remember('key', ['tag'], 60, static function () use (&$calls): string {
            ++$calls;

            return 'from database';
        });

        self::assertSame('from database', $value);
        self::assertSame(1, $calls);
    }

    public function testCacheFailingWhileSavingDoesNotRunTheLoaderTwice(): void
    {
        $cache = new CatalogCache($this->brokenPool(loadFirst: true), new NullLogger(), new RedisCircuitBreaker());
        $calls = 0;

        $value = $cache->remember('key', ['tag'], 60, static function () use (&$calls): string {
            ++$calls;

            return 'from database';
        });

        self::assertSame('from database', $value);
        self::assertSame(1, $calls, 'The already loaded value is reused, the database is not hit again.');
    }

    public function testBrokenCacheDoesNotFailWrites(): void
    {
        $cache = new CatalogCache($this->brokenPool(loadFirst: false), new NullLogger(), new RedisCircuitBreaker());

        $cache->invalidate('tag');

        $this->addToAssertionCount(1);
    }

    public function testLoaderFailuresAreNotMaskedByABrokenCache(): void
    {
        $cache = new CatalogCache($this->brokenPool(loadFirst: true), new NullLogger(), new RedisCircuitBreaker());

        $this->expectException(\DomainException::class);

        $cache->remember('key', [], 60, static function (): never {
            throw new \DomainException('not found');
        });
    }

    private function workingCache(): CatalogCache
    {
        return new CatalogCache(new TagAwareAdapter(new ArrayAdapter()), new NullLogger(), new RedisCircuitBreaker());
    }

    /**
     * A pool whose operations fail like a lost Redis connection. With $loadFirst the failure happens
     * after the callback ran, i.e. while saving the freshly loaded value.
     */
    private function brokenPool(bool $loadFirst): TagAwareCacheInterface
    {
        return new class($loadFirst, new TagAwareAdapter(new ArrayAdapter())) implements TagAwareCacheInterface {
            public function __construct(
                private readonly bool $loadFirst,
                private readonly TagAwareAdapter $source,
            ) {
            }

            /**
             * @param array<string, mixed>|null $metadata
             */
            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                if ($this->loadFirst) {
                    $save = true;
                    $callback($this->source->getItem($key), $save);
                }

                throw new \RuntimeException('Redis connection lost');
            }

            public function delete(string $key): bool
            {
                throw new \RuntimeException('Redis connection lost');
            }

            public function invalidateTags(array $tags): bool
            {
                throw new \RuntimeException('Redis connection lost');
            }
        };
    }
}
