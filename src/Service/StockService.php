<?php

declare(strict_types=1);

namespace App\Service;

use App\Cache\CacheTags;
use App\Cache\CatalogCache;
use App\Dto\Response\StockLevelView;
use App\Dto\Response\StockView;
use App\Dto\Response\WarehouseStockView;
use App\Exception\ApiException;
use App\Http\ViewNormalizer;
use App\Repository\ProductRepository;
use App\Repository\StockRepository;
use App\Repository\WarehouseRepository;

class StockService
{
    private const TTL = 60;

    public function __construct(
        private readonly ProductRepository $products,
        private readonly WarehouseRepository $warehouses,
        private readonly StockRepository $stock,
        private readonly CatalogCache $cache,
        private readonly ViewNormalizer $views,
    ) {
    }

    /**
     * Stock of one product across warehouses. Short TTL: stock is the most volatile data.
     * Tagged with stock.<product> plus one warehouse.<id> tag per warehouse found, so renaming or
     * deleting a warehouse also refreshes every stock view that mentions it.
     *
     * @return array<string, mixed>
     */
    public function get(string $merchantId, string $productId): array
    {
        return $this->cache->remember(
            CacheTags::stockKey($merchantId, $productId),
            static function (array $view) use ($productId): array {
                /** @var list<array{warehouse_id: string}> $warehouses */
                $warehouses = $view['warehouses'];

                return [
                    CacheTags::stock($productId),
                    ...array_map(static fn (array $w): string => CacheTags::warehouse($w['warehouse_id']), $warehouses),
                ];
            },
            self::TTL,
            function () use ($merchantId, $productId): array {
                $this->assertProductExists($merchantId, $productId);

                $rows = array_map(
                    static fn (array $row): WarehouseStockView => new WarehouseStockView(
                        $row['warehouse_id'],
                        $row['code'],
                        $row['name'],
                        $row['quantity'],
                        new \DateTimeImmutable($row['updated_at'], new \DateTimeZone('UTC')),
                    ),
                    $this->stock->findByProduct($productId),
                );

                return $this->views->normalize(new StockView(
                    $productId,
                    array_sum(array_map(static fn (WarehouseStockView $r): int => $r->quantity, $rows)),
                    $rows,
                ));
            },
        );
    }

    /**
     * Sets the absolute on-hand quantity (naturally idempotent).
     *
     * @return array<string, mixed>
     */
    public function set(string $merchantId, string $productId, string $warehouseId, int $quantity): array
    {
        $this->assertOwned($merchantId, $productId, $warehouseId);

        $this->stock->setQuantity($productId, $warehouseId, $quantity, new \DateTimeImmutable());
        $this->cache->invalidate(CacheTags::stock($productId));

        return $this->views->normalize(new StockLevelView($productId, $warehouseId, $quantity));
    }

    /**
     * Applies a signed delta atomically. NOT naturally idempotent: a retried "+5" would add 10,
     * which is why the endpoint demands an Idempotency-Key.
     *
     * @return array<string, mixed>
     */
    public function adjust(string $merchantId, string $productId, string $warehouseId, int $delta): array
    {
        $this->assertOwned($merchantId, $productId, $warehouseId);

        $quantity = $this->stock->adjust($productId, $warehouseId, $delta, new \DateTimeImmutable());

        if (null === $quantity) {
            throw $delta < 0
                ? ApiException::conflict('insufficient_stock', 'Not enough stock in this warehouse for the requested write-off.')
                : ApiException::conflict('stock_limit_exceeded', \sprintf('Stock cannot exceed %d units per warehouse.', StockRepository::MAX_QUANTITY));
        }

        $this->cache->invalidate(CacheTags::stock($productId));

        return $this->views->normalize(new StockLevelView($productId, $warehouseId, $quantity));
    }

    private function assertOwned(string $merchantId, string $productId, string $warehouseId): void
    {
        $this->assertProductExists($merchantId, $productId);

        if (!$this->warehouses->existsForMerchant($merchantId, $warehouseId)) {
            throw ApiException::notFound('Warehouse');
        }
    }

    private function assertProductExists(string $merchantId, string $productId): void
    {
        if (!$this->products->existsForMerchant($merchantId, $productId)) {
            throw ApiException::notFound('Product');
        }
    }
}
