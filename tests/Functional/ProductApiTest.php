<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;

final class ProductApiTest extends ApiTestCase
{
    public function testCreateAndGetProduct(): void
    {
        $merchant = $this->createMerchant();

        $created = $this->requestJson('POST', '/api/v1/products', $merchant->apiKey, [
            'sku' => 'TSHIRT-BLK-M',
            'name' => 'Black T-shirt',
            'description' => 'Organic cotton',
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('/api/v1/products/'.$created['id'], $this->responseHeader('Location'));
        self::assertSame('TSHIRT-BLK-M', $created['sku']);
        self::assertTrue($created['active']);
        self::assertSame([], $created['prices']);
        self::assertArrayHasKey('created_at', $created);

        $fetched = $this->requestJson('GET', '/api/v1/products/'.$created['id'], $merchant->apiKey);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($created['id'], $fetched['id']);
        self::assertSame('Organic cotton', $fetched['description']);
    }

    public function testDuplicateSkuIsAConflict(): void
    {
        $merchant = $this->createMerchant();
        $this->createProduct($merchant, 'DUP-1');

        $body = $this->requestJson('POST', '/api/v1/products', $merchant->apiKey, ['sku' => 'DUP-1', 'name' => 'Again']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('sku_already_exists', $body['code']);
    }

    public function testSameSkuIsAllowedForDifferentMerchants(): void
    {
        $first = $this->createMerchant('First');
        $second = $this->createMerchant('Second');

        $this->createProduct($first, 'SHARED-SKU');
        $this->createProduct($second, 'SHARED-SKU');

        self::assertResponseStatusCodeSame(201);
    }

    public function testValidationErrorsAreReportedPerField(): void
    {
        $merchant = $this->createMerchant();

        $body = $this->requestJson('POST', '/api/v1/products', $merchant->apiKey, ['sku' => 'bad sku!', 'name' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('application/problem+json', $this->responseHeader('Content-Type'));
        self::assertSame('validation_failed', $body['code']);

        $fields = array_column($body['violations'], 'field');
        self::assertContains('sku', $fields);
        self::assertContains('name', $fields);
    }

    public function testWrongTypesAndMissingFieldsAreRejected(): void
    {
        $merchant = $this->createMerchant();

        $this->requestJson('POST', '/api/v1/products', $merchant->apiKey, ['sku' => 123, 'name' => 'x']);
        self::assertResponseStatusCodeSame(422);

        $this->requestJson('POST', '/api/v1/products', $merchant->apiKey, ['name' => 'no sku']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testMalformedJsonIsABadRequest(): void
    {
        $merchant = $this->createMerchant();

        $this->client->request('POST', '/api/v1/products', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_API_KEY' => $merchant->apiKey,
        ], content: '{"sku": ');

        self::assertResponseStatusCodeSame(400);
        self::assertSame('application/problem+json', $this->responseHeader('Content-Type'));
    }

    public function testListIsPaginatedNewestFirst(): void
    {
        $merchant = $this->createMerchant();
        $first = $this->createProduct($merchant, 'P-1');
        $second = $this->createProduct($merchant, 'P-2');
        $third = $this->createProduct($merchant, 'P-3');

        $page1 = $this->requestJson('GET', '/api/v1/products?page=1&per_page=2', $merchant->apiKey);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(3, $page1['meta']['total']);
        self::assertSame([$third['id'], $second['id']], array_column($page1['data'], 'id'));

        $page2 = $this->requestJson('GET', '/api/v1/products?page=2&per_page=2', $merchant->apiKey);
        self::assertSame([$first['id']], array_column($page2['data'], 'id'));
    }

    public function testListSupportsSearchAndActiveFilter(): void
    {
        $merchant = $this->createMerchant();
        $this->createProduct($merchant, 'MUG-1', 'Blue Mug');
        $hidden = $this->createProduct($merchant, 'LAMP-1', 'Desk Lamp');

        $this->requestJson('PUT', '/api/v1/products/'.$hidden['id'], $merchant->apiKey, ['name' => 'Desk Lamp', 'active' => false]);

        $search = $this->requestJson('GET', '/api/v1/products?q=mug', $merchant->apiKey);
        self::assertSame(['MUG-1'], array_column($search['data'], 'sku'));

        $inactive = $this->requestJson('GET', '/api/v1/products?active=false', $merchant->apiKey);
        self::assertSame(['LAMP-1'], array_column($inactive['data'], 'sku'));
    }

    public function testInvalidPaginationIsRejected(): void
    {
        $merchant = $this->createMerchant();

        $this->requestJson('GET', '/api/v1/products?per_page=1000', $merchant->apiKey);

        self::assertResponseStatusCodeSame(422);
    }

    public function testUpdateProduct(): void
    {
        $merchant = $this->createMerchant();
        $product = $this->createProduct($merchant);

        $updated = $this->requestJson('PUT', '/api/v1/products/'.$product['id'], $merchant->apiKey, [
            'name' => 'Renamed',
            'description' => 'New description',
            'active' => false,
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Renamed', $updated['name']);
        self::assertFalse($updated['active']);
        self::assertSame($product['sku'], $updated['sku'], 'The SKU is immutable.');
    }

    public function testDeleteProduct(): void
    {
        $merchant = $this->createMerchant();
        $product = $this->createProduct($merchant);

        $this->request('DELETE', '/api/v1/products/'.$product['id'], $merchant->apiKey);
        self::assertResponseStatusCodeSame(204);

        $this->requestJson('GET', '/api/v1/products/'.$product['id'], $merchant->apiKey);
        self::assertResponseStatusCodeSame(404);
    }

    public function testMerchantsCannotSeeOrTouchEachOthersProducts(): void
    {
        $owner = $this->createMerchant('Owner');
        $intruder = $this->createMerchant('Intruder');
        $product = $this->createProduct($owner);
        $uri = '/api/v1/products/'.$product['id'];

        $this->requestJson('GET', $uri, $intruder->apiKey);
        self::assertResponseStatusCodeSame(404);

        $this->requestJson('PUT', $uri, $intruder->apiKey, ['name' => 'Hijacked', 'active' => true]);
        self::assertResponseStatusCodeSame(404);

        $this->request('DELETE', $uri, $intruder->apiKey);
        self::assertResponseStatusCodeSame(404);

        $list = $this->requestJson('GET', '/api/v1/products', $intruder->apiKey);
        self::assertSame(0, $list['meta']['total']);

        $stillThere = $this->requestJson('GET', $uri, $owner->apiKey);
        self::assertSame($product['name'], $stillThere['name']);
    }

    public function testUnknownAndMalformedIdsAreNotFound(): void
    {
        $merchant = $this->createMerchant();

        $body = $this->requestJson('GET', '/api/v1/products/018f3c2e-0000-7000-8000-000000000000', $merchant->apiKey);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('not_found', $body['code']);

        $this->requestJson('GET', '/api/v1/products/not-a-uuid', $merchant->apiKey);
        self::assertResponseStatusCodeSame(404);
    }
}
