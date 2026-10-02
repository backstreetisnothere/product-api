<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StockLevel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Stock writes are single atomic SQL statements: no SELECT-then-UPDATE, therefore no lost
 * updates and no overselling under concurrency. PostgreSQL row locks serialise writers of
 * the same (product, warehouse) pair; different pairs never contend.
 *
 * @extends ServiceEntityRepository<StockLevel>
 */
class StockRepository extends ServiceEntityRepository
{
    public const MAX_QUANTITY = 1_000_000_000;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockLevel::class);
    }

    public function setQuantity(string $productId, string $warehouseId, int $quantity, \DateTimeImmutable $now): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO stock_levels (id, product_id, warehouse_id, quantity, updated_at)
             VALUES (:id, :product, :warehouse, :quantity, :now)
             ON CONFLICT (product_id, warehouse_id)
             DO UPDATE SET quantity = EXCLUDED.quantity, updated_at = EXCLUDED.updated_at',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'product' => $productId,
                'warehouse' => $warehouseId,
                'quantity' => $quantity,
                'now' => $now,
            ],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * Atomically applies a delta.
     *
     * @return int|null the new quantity, or null when the change would make the stock
     *                  negative (or exceed {@see self::MAX_QUANTITY})
     */
    public function adjust(string $productId, string $warehouseId, int $delta, \DateTimeImmutable $now): ?int
    {
        $connection = $this->getEntityManager()->getConnection();

        if ($delta >= 0) {
            $result = $connection->fetchOne(
                'INSERT INTO stock_levels (id, product_id, warehouse_id, quantity, updated_at)
                 VALUES (:id, :product, :warehouse, :delta, :now)
                 ON CONFLICT (product_id, warehouse_id)
                 DO UPDATE SET quantity = stock_levels.quantity + EXCLUDED.quantity, updated_at = EXCLUDED.updated_at
                 WHERE stock_levels.quantity + EXCLUDED.quantity <= :max
                 RETURNING quantity',
                [
                    'id' => Uuid::v7()->toRfc4122(),
                    'product' => $productId,
                    'warehouse' => $warehouseId,
                    'delta' => $delta,
                    'now' => $now,
                    'max' => self::MAX_QUANTITY,
                ],
                ['now' => Types::DATETIME_IMMUTABLE],
            );
        } else {
            $result = $connection->fetchOne(
                'UPDATE stock_levels
                 SET quantity = quantity + :delta, updated_at = :now
                 WHERE product_id = :product AND warehouse_id = :warehouse AND quantity + :delta >= 0
                 RETURNING quantity',
                [
                    'product' => $productId,
                    'warehouse' => $warehouseId,
                    'delta' => $delta,
                    'now' => $now,
                ],
                ['now' => Types::DATETIME_IMMUTABLE],
            );
        }

        return false === $result ? null : (int) $result;
    }

    /**
     * @return list<array{warehouse_id: string, code: string, name: string, quantity: int, updated_at: string}>
     */
    public function findByProduct(string $productId): array
    {
        /** @var list<array{warehouse_id: string, code: string, name: string, quantity: int|string, updated_at: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT s.warehouse_id, w.code, w.name, s.quantity, s.updated_at
             FROM stock_levels s
             JOIN warehouses w ON w.id = s.warehouse_id
             WHERE s.product_id = :product
             ORDER BY w.code ASC',
            ['product' => $productId],
        );

        return array_map(
            static fn (array $row): array => [
                'warehouse_id' => (string) $row['warehouse_id'],
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'quantity' => (int) $row['quantity'],
                'updated_at' => (string) $row['updated_at'],
            ],
            $rows,
        );
    }
}
