<?php

declare(strict_types=1);

namespace App\Resilience;

use App\Redis\FailFastRedis;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Circuit breaker + active health probe shared by every Redis-backed component (cache, rate limiter,
 * brute-force guard, idempotency).
 *
 * Why it exists: when Redis is down or unreachable every single call may burn its full connect/read
 * timeout (or a DNS timeout, several seconds in container networks), and one request performs several
 * calls. Without a breaker a cache outage multiplies into a full latency outage. With it an outage costs
 * at most one probe per cooldown window, and every component degrades instantly:
 *
 *   cache -> PostgreSQL, rate limiting -> fail-open, idempotent writes -> 503.
 *
 * Why an ACTIVE probe and not only failure counting: the Symfony cache adapters swallow connection errors
 * (they log a warning and report a miss), so the application never sees an exception it could count.
 * The breaker therefore asks Redis itself, at most once per healthyTtlSeconds while healthy and once per
 * cooldownSeconds while broken (half-open probe after the cooldown).
 *
 * Healing: a phpredis handle whose connection was lost stays dead until connect() is called again, so every
 * recorded failure bumps a shared "generation"; each worker re-opens its own connection once per generation
 * (see {@see FailFastRedis::reconnect()}) before probing, instead of staying broken after Redis is back.
 *
 * State lives in APCu, so it is shared by all requests/workers of one container and survives FrankenPHP
 * worker resets. Without APCu (CLI, tests) it falls back to per-process memory.
 */
class RedisCircuitBreaker
{
    private const OPEN_KEY = 'seller-api.redis.circuit-open';
    private const HEALTHY_KEY = 'seller-api.redis.healthy';
    private const GENERATION_KEY = 'seller-api.redis.generation';

    private float $openUntil = 0.0;
    private float $healthyUntil = 0.0;
    private int $localGeneration = 0;
    private int $connectedGeneration = -1;

    public function __construct(
        #[Autowire(service: 'app.redis')]
        private readonly ?\Redis $probe = null,
        private readonly int $cooldownSeconds = 5,
        private readonly int $healthyTtlSeconds = 2,
    ) {
    }

    /**
     * True when Redis must not be used right now.
     */
    public function isOpen(): bool
    {
        if ($this->flag(self::OPEN_KEY, $this->openUntil)) {
            return true;
        }

        if (null === $this->probe) {
            return false;
        }

        $this->ensureConnection($this->probe);

        if ($this->flag(self::HEALTHY_KEY, $this->healthyUntil)) {
            return false;
        }

        return !$this->probe($this->probe);
    }

    /**
     * Forced probe, ignoring cached verdicts (used by the readiness endpoint).
     */
    public function check(): bool
    {
        if (null === $this->probe) {
            return true;
        }

        $this->ensureConnection($this->probe);

        return $this->probe($this->probe);
    }

    /**
     * Called by components that observed a Redis failure themselves.
     */
    public function recordFailure(): void
    {
        $this->remember(self::OPEN_KEY, $this->cooldownSeconds, $this->openUntil);
        $this->bumpGeneration();

        $this->healthyUntil = 0.0;
        if ($this->apcuAvailable()) {
            apcu_delete(self::HEALTHY_KEY);
        }
    }

    private function probe(\Redis $redis): bool
    {
        try {
            $redis->ping();
        } catch (\Throwable) {
            $this->recordFailure();

            return false;
        }

        $this->remember(self::HEALTHY_KEY, $this->healthyTtlSeconds, $this->healthyUntil);

        return true;
    }

    /**
     * Makes sure THIS worker's handle is usable: connects a fresh handle, and re-opens a handle that was
     * alive when a failure happened (generation changed) because a lost phpredis connection never recovers.
     */
    private function ensureConnection(\Redis $redis): void
    {
        if (!$redis instanceof FailFastRedis) {
            return;
        }

        $generation = $this->generation();

        if ($generation === $this->connectedGeneration && $redis->isConnected()) {
            return;
        }

        $redis->reconnect();
        $this->connectedGeneration = $generation;
    }

    private function generation(): int
    {
        if ($this->apcuAvailable()) {
            return (int) apcu_fetch(self::GENERATION_KEY);
        }

        return $this->localGeneration;
    }

    private function bumpGeneration(): void
    {
        if ($this->apcuAvailable()) {
            apcu_inc(self::GENERATION_KEY, 1, $success, 3600);

            return;
        }

        ++$this->localGeneration;
    }

    private function flag(string $key, float $localUntil): bool
    {
        return $this->apcuAvailable() ? apcu_exists($key) : microtime(true) < $localUntil;
    }

    private function remember(string $key, int $ttlSeconds, float &$localUntil): void
    {
        if ($this->apcuAvailable()) {
            apcu_store($key, 1, $ttlSeconds);

            return;
        }

        $localUntil = microtime(true) + $ttlSeconds;
    }

    private function apcuAvailable(): bool
    {
        return \function_exists('apcu_enabled') && apcu_enabled();
    }
}
