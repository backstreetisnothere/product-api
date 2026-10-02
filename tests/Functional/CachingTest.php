<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Proves the Cache-Aside contract black-box: a value changed "behind the back" of the application
 * (straight in the database) stays invisible while the cache entry lives, and every API write that
 * should invalidate it makes the fresh value visible immediately.
 */
final class CachingTest extends ApiTestCase
{
    public function testProductReadsAreServedFromCacheUntilAWriteInvalidatesThem(): void
    {
        $merchant = $this->createMerchant();
        $product = $this->createProduct($merchant, 'CACHE-1', 'Original');
        $uri = '/api/v1/products/'.$product['id'];

        self::assertSame('Original', $this->requestJson('GET', $uri, $merchant->apiKey)['name']);

        $this->updateBehindTheScenes('UPDATE products SET name = ? WHERE id = ?', ['Changed in DB', $product['id']]);

        self::assertSame('Original', $this->requestJson('GET', $uri, $merchant->apiKey)['name'], 'Second read must be a cache hit.');

        $this->requestJson('PUT', $uri, $merchant->apiKey, ['name' => 'Updated via API', 'active' => true]);

        self::assertSame('Updated via API', $this->requestJson('GET', $uri, $merchant->apiKey)['name'], 'The write must invalidate the entry.');
    }

    public function testProductListIsInvalidatedWhenAProductIsCreated(): void
    {
        $merchant = $this->createMerchant();

        self::assertSame(0, $this->requestJson('GET', '/api/v1/products', $merchant->apiKey)['meta']['total']);

        $this->createProduct($merchant);

        self::assertSame(1, $this->requestJson('GET', '/api/v1/products', $merchant->apiKey)['meta']['total']);
    }

    public function testListIsServedFromCacheAndInvalidatedByPriceChanges(): void
    {
        $merchant = $this->createMerchant();
        $product = $this->createProduct($merchant);

        $this->requestJson('GET', '/api/v1/products', $merchant->apiKey);
        $this->updateBehindTheScenes('UPDATE products SET name = ? WHERE id = ?', ['Changed in DB', $product['id']]);

        $cached = $this->requestJson('GET', '/api/v1/products', $merchant->apiKey);
        self::assertSame('Test product', $cached['data'][0]['name'], 'The list must be a cache hit.');

        $this->requestJson('PUT', '/api/v1/products/'.$product['id'].'/prices/RUB', $merchant->apiKey, ['amount' => 500]);

        $fresh = $this->requestJson('GET', '/api/v1/products', $merchant->apiKey);
        self::assertSame('Changed in DB', $fresh['data'][0]['name'], 'A price change invalidates the merchant product tag.');
        self::assertSame(500, $fresh['data'][0]['prices'][0]['amount']);
    }

    public function testStockViewIsInvalidatedByStockChanges(): void
    {
        $merchant = $this->createMerchant();
        $product = $this->createProduct($merchant);
        $warehouse = $this->createWarehouse($merchant);
        $base = '/api/v1/products/'.$product['id'].'/stock';

        self::assertSame(0, $this->requestJson('GET', $base, $merchant->apiKey)['total']);

        $this->requestJson('POST', $base.'/'.$warehouse['id'].'/adjustments', $merchant->apiKey, ['delta' => 4], ['Idempotency-Key' => 'cache-test-0001']);

        self::assertSame(4, $this->requestJson('GET', $base, $merchant->apiKey)['total']);
    }

    public function testRenamingAWarehouseRefreshesStockViewsThroughItsTag(): void
    {
        $merchant = $this->createMerchant();
        $product = $this->createProduct($merchant);
        $warehouse = $this->createWarehouse($merchant, 'TAG-01');
        $base = '/api/v1/products/'.$product['id'].'/stock';

        $this->requestJson('PUT', $base.'/'.$warehouse['id'], $merchant->apiKey, ['quantity' => 2]);
        self::assertSame('Test warehouse', $this->requestJson('GET', $base, $merchant->apiKey)['warehouses'][0]['name']);

        // The stock view carries the tag "warehouse.<id>": a warehouse update invalidates it, although no stock changed.
        $this->requestJson('PUT', '/api/v1/warehouses/'.$warehouse['id'], $merchant->apiKey, ['name' => 'Renamed warehouse']);

        self::assertSame('Renamed warehouse', $this->requestJson('GET', $base, $merchant->apiKey)['warehouses'][0]['name']);
    }

    public function testDeletedProductsDisappearFromTheCache(): void
    {
        $merchant = $this->createMerchant();
        $product = $this->createProduct($merchant);
        $uri = '/api/v1/products/'.$product['id'];

        $this->requestJson('GET', $uri, $merchant->apiKey);
        $this->request('DELETE', $uri, $merchant->apiKey);

        $this->requestJson('GET', $uri, $merchant->apiKey);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCacheNeverLeaksDataAcrossMerchants(): void
    {
        $owner = $this->createMerchant('Owner');
        $other = $this->createMerchant('Other');
        $product = $this->createProduct($owner);
        $uri = '/api/v1/products/'.$product['id'];

        $this->requestJson('GET', $uri, $owner->apiKey);
        self::assertResponseStatusCodeSame(200, 'Warm the cache for the owner.');

        $this->requestJson('GET', $uri, $other->apiKey);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param list<string> $params
     */
    private function updateBehindTheScenes(string $sql, array $params): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement($sql, $params);
    }
}
