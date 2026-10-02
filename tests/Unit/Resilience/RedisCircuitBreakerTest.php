<?php

declare(strict_types=1);

namespace App\Tests\Unit\Resilience;

use App\Resilience\RedisCircuitBreaker;
use PHPUnit\Framework\TestCase;

final class RedisCircuitBreakerTest extends TestCase
{
    public function testIsClosedInitiallyWithoutAProbe(): void
    {
        self::assertFalse((new RedisCircuitBreaker())->isOpen());
    }

    public function testOpensAfterARecordedFailureAndClosesAfterTheCooldown(): void
    {
        $breaker = new RedisCircuitBreaker(cooldownSeconds: 1);

        $breaker->recordFailure();
        self::assertTrue($breaker->isOpen(), 'Redis is skipped right after a failure.');

        usleep(1_200_000);
        self::assertFalse($breaker->isOpen(), 'After the cooldown the next request probes Redis again.');
    }

    public function testAHealthyProbeIsRememberedSoRedisIsNotPingedOnEveryRequest(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())->method('ping')->willReturn(true);
        $breaker = new RedisCircuitBreaker($redis, cooldownSeconds: 5, healthyTtlSeconds: 60);

        self::assertFalse($breaker->isOpen());
        self::assertFalse($breaker->isOpen());
        self::assertFalse($breaker->isOpen());
    }

    public function testAFailedProbeOpensTheCircuitAndIsNotRepeatedDuringTheCooldown(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())->method('ping')->willThrowException(new \RedisException('connection refused'));
        $breaker = new RedisCircuitBreaker($redis, cooldownSeconds: 60);

        self::assertTrue($breaker->isOpen());
        self::assertTrue($breaker->isOpen(), 'While open, Redis is not contacted again.');
        self::assertTrue($breaker->isOpen());
    }

    public function testTheCircuitClosesAgainWhenTheProbeSucceedsAfterTheCooldown(): void
    {
        $pings = 0;
        $redis = $this->createMock(\Redis::class);
        $redis->method('ping')->willReturnCallback(static function () use (&$pings): bool {
            if (1 === ++$pings) {
                throw new \RedisException('down');
            }

            return true;
        });
        $breaker = new RedisCircuitBreaker($redis, cooldownSeconds: 1, healthyTtlSeconds: 60);

        self::assertTrue($breaker->isOpen());

        usleep(1_200_000);

        self::assertFalse($breaker->isOpen(), 'Half-open probe succeeded: Redis is used again.');
        self::assertSame(2, $pings);
    }
}
