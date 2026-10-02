<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;

final class WarehouseApiTest extends ApiTestCase
{
    public function testWarehouseLifecycle(): void
    {
        $merchant = $this->createMerchant();

        $created = $this->requestJson('POST', '/api/v1/warehouses', $merchant->apiKey, [
            'code' => 'MSK-01',
            'name' => 'Moscow main',
            'city' => 'Moscow',
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('/api/v1/warehouses/'.$created['id'], $this->responseHeader('Location'));

        $fetched = $this->requestJson('GET', '/api/v1/warehouses/'.$created['id'], $merchant->apiKey);
        self::assertSame('MSK-01', $fetched['code']);

        $updated = $this->requestJson('PUT', '/api/v1/warehouses/'.$created['id'], $merchant->apiKey, ['name' => 'Moscow hub']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Moscow hub', $updated['name']);
        self::assertNull($updated['city']);
        self::assertSame('MSK-01', $updated['code'], 'The code is immutable.');

        $list = $this->requestJson('GET', '/api/v1/warehouses', $merchant->apiKey);
        self::assertSame([$created['id']], array_column($list['data'], 'id'));

        $this->request('DELETE', '/api/v1/warehouses/'.$created['id'], $merchant->apiKey);
        self::assertResponseStatusCodeSame(204);

        $this->requestJson('GET', '/api/v1/warehouses/'.$created['id'], $merchant->apiKey);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDuplicateCodeIsAConflict(): void
    {
        $merchant = $this->createMerchant();
        $this->createWarehouse($merchant, 'DUP-01');

        $body = $this->requestJson('POST', '/api/v1/warehouses', $merchant->apiKey, ['code' => 'DUP-01', 'name' => 'Again']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('warehouse_code_already_exists', $body['code']);
    }

    public function testCodeFormatIsValidated(): void
    {
        $merchant = $this->createMerchant();

        $body = $this->requestJson('POST', '/api/v1/warehouses', $merchant->apiKey, ['code' => 'lower case', 'name' => 'x']);

        self::assertResponseStatusCodeSame(422);
        self::assertContains('code', array_column($body['violations'], 'field'));
    }

    public function testMerchantsOnlySeeTheirOwnWarehouses(): void
    {
        $owner = $this->createMerchant('Owner');
        $other = $this->createMerchant('Other');
        $warehouse = $this->createWarehouse($owner);

        $this->requestJson('GET', '/api/v1/warehouses/'.$warehouse['id'], $other->apiKey);
        self::assertResponseStatusCodeSame(404);

        $list = $this->requestJson('GET', '/api/v1/warehouses', $other->apiKey);
        self::assertSame([], $list['data']);
    }
}
