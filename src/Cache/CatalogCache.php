<?php

declare(strict_types=1);

namespace App\Cache;

use App\Resilience\RedisCircuitBreaker;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Cache-Aside facade over a tag-aware Redis pool.
 *
 *  - reads:  remember() returns the cached value or computes it with the loader and stores it
 *            (Symfony's get() adds stampede protection: a lock + probabilistic early expiration);
 *  - writes: the caller commits to the database FIRST and then calls invalidate() with the tags of
 *            everything that changed. The TTL is the safety net for the tiny window in which a
 *            concurrent reader can repopulate the cache with pre-commit data;
 *  - failures: the cache is an optimisation, never a dependency. If Redis is down or slow the loader
 *            result is returned directly and the incident is logged; the circuit breaker then keeps
 *            requests away from Redis for a few seconds so that an outage costs one timeout, not one
 *            per call. Errors thrown by the loader itself (DB down, 404, ...) are never swallowed.
 *            Entries that could not be invalidated during an outage expire by TTL.
 */
class CatalogCache
{
    /** Misses (null results) are cached briefly to absorb repeated lookups of unknown keys. */
    private const NEGATIVE_TTL = 10;

    public function __construct(
        #[Autowire(service: 'cache.catalog')]
        private readonly TagAwareCacheInterface $pool,
        private readonly LoggerInterface $logger,
        private readonly RedisCircuitBreaker $breaker,
    ) {
    }

    /**
     * @template T
     *
     * @param list<string>|\Closure(T): list<string> $tags   tags, or a closure deriving them from the loaded value
     *                                                       (for keys whose tags are only known after loading)
     * @param callable(): T                          $loader
     *
     * @return T
     */
    public function remember(string $key, array|\Closure $tags, int $ttl, callable $loader): mixed
    {
        if ($this->breaker->isOpen()) {
            return $loader();
        }

        $state = new LoadState();

        try {
            return $this->pool->get(
                $key,
                static function (ItemInterface $item) use ($loader, $tags, $ttl, $state): mixed {
                    try {
                        $state->value = $loader();
                        $state->loaded = true;
                    } catch (\Throwable $e) {
                        $state->failure = $e;

                        throw $e;
                    }

                    $item->tag($tags instanceof \Closure ? $tags($state->value) : $tags);
                    $item->expiresAfter(null === $state->value ? self::NEGATIVE_TTL : $ttl);

                    return $state->value;
                },
            );
        } catch (\Throwable $e) {
            if ($e === $state->failure) {
                throw $e;
            }

            $this->breaker->recordFailure();
            $this->logger->warning('Catalog cache unavailable, serving from the source of truth.', [
                'key' => $key,
                'exception' => $e,
            ]);

            return $state->loaded ? $state->value : $loader();
        }
    }

    /**
     * Invalidates every entry carrying at least one of the given tags.
     */
    public function invalidate(string ...$tags): void
    {
        if ([] === $tags) {
            return;
        }

        if ($this->breaker->isOpen()) {
            $this->logger->warning('Catalog cache circuit is open, invalidation skipped, entries will expire by TTL.', ['tags' => $tags]);

            return;
        }

        try {
            $this->pool->invalidateTags(array_values(array_unique($tags)));
        } catch (\Throwable $e) {
            // Stale data is bounded by the TTL; failing the (already committed) write would be worse.
            $this->breaker->recordFailure();
            $this->logger->error('Catalog cache invalidation failed, entries will expire by TTL.', [
                'tags' => $tags,
                'exception' => $e,
            ]);
        }
    }
}
