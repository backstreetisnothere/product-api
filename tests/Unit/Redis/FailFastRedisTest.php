<?php

declare(strict_types=1);

namespace App\Tests\Unit\Redis;

use App\Redis\FailFastRedis;
use PHPUnit\Framework\TestCase;

/**
 * Talks to the real Redis of the test environment (REDIS_URL) and to a closed port.
 */
final class FailFastRedisTest extends TestCase
{
    public function testCreationNeverTouchesTheNetwork(): void
    {
        $started = microtime(true);
        $redis = FailFastRedis::fromDsn('redis://unresolvable.invalid:6379?timeout=0.2');

        self::assertFalse($redis->isConnected());
        self::assertLessThan(0.5, microtime(true) - $started);
    }

    public function testRejectsUnsupportedDsns(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FailFastRedis::fromDsn('mysql://localhost');
    }

    public function testReconnectReportsAnUnreachableServerWithoutThrowing(): void
    {
        $redis = FailFastRedis::fromDsn('redis://127.0.0.1:1?timeout=0.2');

        self::assertFalse($redis->reconnect());
        self::assertFalse($redis->isConnected());
    }

    public function testReconnectHealsAConnectionThatWasClosed(): void
    {
        $redis = FailFastRedis::fromDsn((string) ($_SERVER['REDIS_URL'] ?? 'redis://redis:6379'));

        self::assertTrue($redis->reconnect());
        self::assertTrue((bool) $redis->ping());

        $redis->close();
        self::assertFalse($redis->isConnected(), 'A closed handle stays dead ...');

        self::assertTrue($redis->reconnect(), '... until it is re-opened explicitly.');
        self::assertTrue((bool) $redis->ping());
    }

    public function testDriverRetriesAreDisabled(): void
    {
        $redis = FailFastRedis::fromDsn((string) ($_SERVER['REDIS_URL'] ?? 'redis://redis:6379'));
        $redis->reconnect();

        self::assertSame(0, $redis->getOption(\Redis::OPT_MAX_RETRIES));
    }
}
