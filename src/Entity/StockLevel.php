<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StockRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Stock of one product in one warehouse.
 *
 * This is the hottest write path of the API, so it is never loaded/flushed through the
 * UnitOfWork (lost updates!). All mutations are single atomic SQL statements, see
 * {@see StockRepository}. The non-negative invariant is additionally enforced by a
 * CHECK constraint in the migration.
 */
#[ORM\Entity(repositoryClass: StockRepository::class)]
#[ORM\Table(name: 'stock_levels')]
#[ORM\UniqueConstraint(name: 'uniq_stock_levels_product_warehouse', columns: ['product_id', 'warehouse_id'])]
#[ORM\Index(name: 'idx_stock_levels_warehouse', columns: ['warehouse_id'])]
class StockLevel
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Warehouse $warehouse;

    #[ORM\Column]
    private int $quantity;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Product $product, Warehouse $warehouse, int $quantity)
    {
        $this->id = Uuid::v7();
        $this->product = $product;
        $this->warehouse = $warehouse;
        $this->quantity = $quantity;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getWarehouse(): Warehouse
    {
        return $this->warehouse;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
