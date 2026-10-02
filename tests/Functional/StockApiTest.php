<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;

final class StockApiTest extends ApiTestCase
{
    public function testSetAndReadStockAcrossWarehouses(): void
    {
        $merchant = $this->createMerchant();
        $product = $this->createProduct($merchant);
        $first = $this->createWarehouse($merchant, 'AAA-01');
        $second = $this->createWarehouse($merchant, 'BBB-01');
        $base = '/api/v1/products/'.$product['id'].'/stock';

        $level = $this->requestJson('PUT', $base.'/'.$first['id'], $merchant->apiKey, ['quantity' => 10]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(10, $level['quantity']);

        $this->requestJson('PUT', $base.'/'.$second['id'], $merchant->apiKey, ['quantity' => 5]);
        $this->requestJson('PUT', $base.'/'.$first['id'], $merchant->apiKey, ['quantity' => 12]);

        $stock = $this->requestJson('GET', $base, $merchant->apiKey);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(17, $stock['total']);
        self::assertSame(['AAA-01' => 12, 'BBB-01' => 5], array_column($stock['warehouses'], 'quantity', 'code'));
    }

    public function testAdjustAppliesSignedDeltas(): void
    {
        $merchant = $this->createMerchant();
        $product = $this->createProduct($merchant);
        $warehouse = $this->createWarehouse($merchant);
        $uri = '/api/v1/products/'.$product['id'].'/stock/'.$warehouse['id'].'/adjustments';

        $received = $this->requestJson('POST', $uri, $merchant->apiKey, ['delta' => 20], ['Idempotency-Key' => 'receipt-0001']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(20, $received['quantity'], 'Adjusting a missing row creates it.');

        $sold = $this->requestJson('POST', $uri, $merchant->apiKey, ['delta' => -7], ['Idempotency-Key' => 'sale-0001']);
        self::assertSame(13, $sold['quantity']);
    }

    public function testStockCanNeverGoNegative(): void
    {
        $merchant = $this->createMerchant();
        $product = $this->createProduct($merchant);
        $warehouse = $this->createWarehouse($merchant);
        $base = '/api/v1/products/'.$product['id'].'/stock';

        $this->requestJson('PUT', $base.'/'.$warehouse['id'], $merchant->apiKey, ['quantity' => 3]);

        $body = $this->requestJson('POST', $base.'/'.$warehouse['id'].'/adjustments', $merchant->apiKey, ['delta' => -4], ['Idempotency-Key' => 'oversell-01']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('insufficient_stock', $body['code']);

        $stock = $this->requestJson('GET', $base, $merchant->apiKey);
        self::assertSame(3, $stock['total'], 'A rejected adjustment must not change anything.');
    }

    public function testWritingOffFromAnUntouchedWarehouseIsRejected(): void
    {
        $merchant = $this->createMerchant();
        $product = $this->createProduct($merchant);
        $warehouse = $this->createWarehouse($merchant);

        $body = $this->requestJson(
            'POST',
            '/api/v1/products/'.$product['id'].'/stock/'.$warehouse['id'].'/adjustments',
            $merchant->apiKey,
            ['delta' => -1],
            ['Idempotency-Key' => 'oversell-02'],
        );

        self::assertResponseStatusCodeSame(409);
        self::assertSame('insufficient_stock', $body['code']);
    }

    public function testPayloadIsValidated(): void
    {
        $merchant = $this->createMerchant();
        $product = $this->createProduct($merchant);
        $warehouse = $this->createWarehouse($merchant);
        $base = '/api/v1/products/'.$product['id'].'/stock/'.$warehouse['id'];

        $this->requestJson('POST', $base.'/adjustments', $merchant->apiKey, ['delta' => 0], ['Idempotency-Key' => 'zero-delta-01']);
        self::assertResponseStatusCodeSame(422);

        $this->requestJson('PUT', $base, $merchant->apiKey, ['quantity' => -1]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testStockOfForeignProductOrWarehouseIsNotReachable(): void
    {
        $owner = $this->createMerchant('Owner');
        $intruder = $this->createMerchant('Intruder');
        $product = $this->createProduct($owner);
        $warehouse = $this->createWarehouse($owner);
        $ownWarehouse = $this->createWarehouse($intruder);

        $this->requestJson('GET', '/api/v1/products/'.$product['id'].'/stock', $intruder->apiKey);
        self::assertResponseStatusCodeSame(404);

        $this->requestJson('PUT', '/api/v1/products/'.$product['id'].'/stock/'.$ownWarehouse['id'], $intruder->apiKey, ['quantity' => 1]);
        self::assertResponseStatusCodeSame(404, 'Foreign product.');

        $ownProduct = $this->createProduct($intruder);
        $this->requestJson('PUT', '/api/v1/products/'.$ownProduct['id'].'/stock/'.$warehouse['id'], $intruder->apiKey, ['quantity' => 1]);
        self::assertResponseStatusCodeSame(404, 'Foreign warehouse.');
    }

    public function testDeletingAWarehouseRemovesItsStock(): void
    {
        $merchant = $this->createMerchant();
        $product = $this->createProduct($merchant);
        $warehouse = $this->createWarehouse($merchant);
        $base = '/api/v1/products/'.$product['id'].'/stock';

        $this->requestJson('PUT', $base.'/'.$warehouse['id'], $merchant->apiKey, ['quantity' => 9]);
        self::assertSame(9, $this->requestJson('GET', $base, $merchant->apiKey)['total']);

        $this->request('DELETE', '/api/v1/warehouses/'.$warehouse['id'], $merchant->apiKey);
        self::assertResponseStatusCodeSame(204);

        $stock = $this->requestJson('GET', $base, $merchant->apiKey);
        self::assertSame(0, $stock['total']);
        self::assertSame([], $stock['warehouses']);
    }
}
