<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Idempotency\IdempotencyStoreInterface;
use App\Redis\FailFastRedis;
use App\Tests\Support\ApiTestCase;

final class IdempotencyTest extends ApiTestCase
{
    public function testRetryWithSameKeyReplaysTheOriginalResponse(): void
    {
        $merchant = $this->createMerchant();
        $payload = ['sku' => 'IDEM-1', 'name' => 'Idempotent product'];
        $headers = ['Idempotency-Key' => 'create-product-0001'];

        $first = $this->requestJson('POST', '/api/v1/products', $merchant->apiKey, $payload, $headers);
        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->responseHeader('Idempotent-Replayed'));
        $location = $this->responseHeader('Location');

        // Without idempotency this retry would be a 409 (duplicate SKU). With it the client gets the original answer.
        $second = $this->requestJson('POST', '/api/v1/products', $merchant->apiKey, $payload, $headers);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('true', $this->responseHeader('Idempotent-Replayed'));
        self::assertSame($location, $this->responseHeader('Location'));
        self::assertSame($first, $second);

        $list = $this->requestJson('GET', '/api/v1/products', $merchant->apiKey);
        self::assertSame(1, $list['meta']['total'], 'The operation must have been executed exactly once.');
    }

    public function testRetriedStockAdjustmentIsAppliedOnlyOnce(): void
    {
        $merchant = $this->createMerchant();
        $product = $this->createProduct($merchant);
        $warehouse = $this->createWarehouse($merchant);
        $base = '/api/v1/products/'.$product['id'].'/stock';
        $headers = ['Idempotency-Key' => 'receipt-7f3a9c'];

        $this->requestJson('PUT', $base.'/'.$warehouse['id'], $merchant->apiKey, ['quantity' => 10]);

        $first = $this->requestJson('POST', $base.'/'.$warehouse['id'].'/adjustments', $merchant->apiKey, ['delta' => 5], $headers);
        $retry = $this->requestJson('POST', $base.'/'.$warehouse['id'].'/adjustments', $merchant->apiKey, ['delta' => 5], $headers);

        self::assertSame(15, $first['quantity']);
        self::assertSame(15, $retry['quantity']);
        self::assertSame('true', $this->responseHeader('Idempotent-Replayed'));
        self::assertSame(15, $this->requestJson('GET', $base, $merchant->apiKey)['total']);
    }

    public function testNonIdempotentOperationRequiresTheHeader(): void
    {
        $merchant = $this->createMerchant();
        $product = $this->createProduct($merchant);
        $warehouse = $this->createWarehouse($merchant);

        $body = $this->requestJson(
            'POST',
            '/api/v1/products/'.$product['id'].'/stock/'.$warehouse['id'].'/adjustments',
            $merchant->apiKey,
            ['delta' => 1],
        );

        self::assertResponseStatusCodeSame(400);
        self::assertSame('idempotency_key_required', $body['code']);
    }

    public function testMalformedKeysAreRejected(): void
    {
        $merchant = $this->createMerchant();

        foreach (['short', str_repeat('a', 129), 'has spaces in it', 'bad/char/key-1'] as $key) {
            $body = $this->requestJson('POST', '/api/v1/products', $merchant->apiKey, ['sku' => 'X-1', 'name' => 'x'], ['Idempotency-Key' => $key]);

            self::assertResponseStatusCodeSame(400, \sprintf('Key "%s" must be rejected.', $key));
            self::assertSame('idempotency_key_invalid', $body['code']);
        }
    }

    public function testReusingAKeyWithADifferentPayloadIsRejected(): void
    {
        $merchant = $this->createMerchant();
        $headers = ['Idempotency-Key' => 'reuse-key-0001'];

        $this->requestJson('POST', '/api/v1/products', $merchant->apiKey, ['sku' => 'A-1', 'name' => 'First'], $headers);
        self::assertResponseStatusCodeSame(201);

        $body = $this->requestJson('POST', '/api/v1/products', $merchant->apiKey, ['sku' => 'A-2', 'name' => 'Second'], $headers);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('idempotency_key_reused', $body['code']);
    }

    public function testParallelDuplicateWhileTheFirstRequestIsInFlightGetsAConflict(): void
    {
        $merchant = $this->createMerchant();
        $key = 'in-flight-0001';

        // Simulate request #1 being in flight: it holds the lock but has not produced a response yet.
        // In production the circuit breaker opens the (lazy) connection before any Redis access.
        /** @var FailFastRedis $redis */
        $redis = static::getContainer()->get('app.redis');
        self::assertTrue($redis->reconnect());

        /** @var IdempotencyStoreInterface $store */
        $store = static::getContainer()->get(IdempotencyStoreInterface::class);
        self::assertTrue($store->acquireLock($merchant->id.':'.hash('sha256', $key), 'request-one'));

        $body = $this->requestJson('POST', '/api/v1/products', $merchant->apiKey, ['sku' => 'RACE-1', 'name' => 'Race'], ['Idempotency-Key' => $key]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('idempotency_request_in_progress', $body['code']);
        self::assertSame('1', $this->responseHeader('Retry-After'));
    }

    public function testFailedRequestsAreNotStoredSoTheyCanBeRetried(): void
    {
        $merchant = $this->createMerchant();
        $product = $this->createProduct($merchant);
        $warehouse = $this->createWarehouse($merchant);
        $base = '/api/v1/products/'.$product['id'].'/stock/'.$warehouse['id'];
        $headers = ['Idempotency-Key' => 'write-off-0001'];

        $this->requestJson('POST', $base.'/adjustments', $merchant->apiKey, ['delta' => -5], $headers);
        self::assertResponseStatusCodeSame(409);

        // The stock arrives, the client retries the very same request with the very same key.
        $this->requestJson('PUT', $base, $merchant->apiKey, ['quantity' => 8]);
        $retry = $this->requestJson('POST', $base.'/adjustments', $merchant->apiKey, ['delta' => -5], $headers);

        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->responseHeader('Idempotent-Replayed'));
        self::assertSame(3, $retry['quantity']);
    }

    public function testKeysAreScopedPerMerchant(): void
    {
        $first = $this->createMerchant('First');
        $second = $this->createMerchant('Second');
        $headers = ['Idempotency-Key' => 'shared-key-0001'];
        $payload = ['sku' => 'SAME-1', 'name' => 'Same'];

        $a = $this->requestJson('POST', '/api/v1/products', $first->apiKey, $payload, $headers);
        $b = $this->requestJson('POST', '/api/v1/products', $second->apiKey, $payload, $headers);

        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->responseHeader('Idempotent-Replayed'), 'Another merchant must never receive a replay.');
        self::assertNotSame($a['id'], $b['id']);
    }

    public function testRequestsWithoutTheHeaderAreNotAffected(): void
    {
        $merchant = $this->createMerchant();

        $this->requestJson('POST', '/api/v1/products', $merchant->apiKey, ['sku' => 'PLAIN-1', 'name' => 'Plain']);
        self::assertResponseStatusCodeSame(201);

        $this->requestJson('POST', '/api/v1/products', $merchant->apiKey, ['sku' => 'PLAIN-1', 'name' => 'Plain']);
        self::assertResponseStatusCodeSame(409);
    }
}
