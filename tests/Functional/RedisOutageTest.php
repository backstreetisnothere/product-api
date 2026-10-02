<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;

/**
 * Redis is an accelerator and a guard, not the source of truth. These tests point the application at an
 * unreachable Redis (nothing listens on port 1) and assert the documented degradation policy:
 *
 *   reads            -> served from PostgreSQL (cache bypassed)
 *   plain writes     -> succeed (cache invalidation is skipped, entries expire by TTL)
 *   rate limiting    -> fail-open (requests are served, no quota headers)
 *   idempotent writes-> fail-closed with 503 (exactly-once cannot be guaranteed)
 *   readiness probe  -> reports the outage with 503
 */
final class RedisOutageTest extends ApiTestCase
{
    private ?string $originalRedisUrl = null;

    protected function setUp(): void
    {
        $this->originalRedisUrl = \is_string($_SERVER['REDIS_URL'] ?? null) ? $_SERVER['REDIS_URL'] : null;
        $_SERVER['REDIS_URL'] = $_ENV['REDIS_URL'] = 'redis://127.0.0.1:1?timeout=0.2&read_timeout=0.2';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (null === $this->originalRedisUrl) {
            unset($_SERVER['REDIS_URL'], $_ENV['REDIS_URL']);
        } else {
            $_SERVER['REDIS_URL'] = $_ENV['REDIS_URL'] = $this->originalRedisUrl;
        }
    }

    public function testReadsAreServedFromTheDatabaseWhenRedisIsDown(): void
    {
        $merchant = $this->createMerchant();

        // Warm up (container compilation on a cold cache must not count as latency).
        $this->requestJson('GET', '/api/v1/warehouses', $merchant->apiKey);

        $started = microtime(true);
        $body = $this->requestJson('GET', '/api/v1/products', $merchant->apiKey);
        $elapsed = microtime(true) - $started;

        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $body['meta']['total']);
        self::assertNull($this->responseHeader('X-RateLimit-Limit'), 'The limiter fails open without Redis.');
        self::assertLessThan(2.0, $elapsed, 'The circuit breaker keeps an outage from multiplying timeouts.');
    }

    public function testPlainWritesStillWork(): void
    {
        $merchant = $this->createMerchant();

        $product = $this->createProduct($merchant, 'OUTAGE-1');

        $fetched = $this->requestJson('GET', '/api/v1/products/'.$product['id'], $merchant->apiKey);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('OUTAGE-1', $fetched['sku']);
    }

    public function testIdempotentWritesFailClosed(): void
    {
        $merchant = $this->createMerchant();

        $body = $this->requestJson(
            'POST',
            '/api/v1/products',
            $merchant->apiKey,
            ['sku' => 'OUTAGE-2', 'name' => 'Must not be created'],
            ['Idempotency-Key' => 'outage-key-0001'],
        );

        self::assertResponseStatusCodeSame(503);
        self::assertSame('idempotency_unavailable', $body['code']);
        self::assertNotNull($this->responseHeader('Retry-After'));

        // Nothing was executed, so a retry after recovery cannot create a duplicate.
        $_SERVER['REDIS_URL'] = $_ENV['REDIS_URL'] = $this->originalRedisUrl ?? 'redis://redis:6379';
        $this->client->getKernel()->shutdown();
        $list = $this->requestJson('GET', '/api/v1/products', $merchant->apiKey);
        self::assertSame(0, $list['meta']['total']);
    }

    public function testBruteForceGuardFailsOpenButBadKeysAreStillRejected(): void
    {
        $this->requestJson('GET', '/api/v1/products', 'sk_000000000000_000000000000000000000000000000000000000000000000');

        self::assertResponseStatusCodeSame(401);
    }

    public function testReadinessProbeReportsTheOutage(): void
    {
        $body = $this->requestJson('GET', '/api/v1/health');

        self::assertResponseStatusCodeSame(503);
        self::assertSame('degraded', $body['status']);
        self::assertSame('up', $body['checks']['database']);
        self::assertSame('down', $body['checks']['redis']);
    }
}
