<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductPriceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Price of a product in one currency, stored in minor units (cents/kopecks) to avoid float errors.
 * Written through an atomic UPSERT, see {@see ProductPriceRepository::upsert()}.
 */
#[ORM\Entity(repositoryClass: ProductPriceRepository::class)]
#[ORM\Table(name: 'product_prices')]
#[ORM\UniqueConstraint(name: 'uniq_product_prices_product_currency', columns: ['product_id', 'currency'])]
class ProductPrice
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(length: 3, options: ['fixed' => true])]
    private string $currency;

    #[ORM\Column]
    private int $amount;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Product $product, string $currency, int $amount)
    {
        $this->id = Uuid::v7();
        $this->product = $product;
        $this->currency = $currency;
        $this->amount = $amount;
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

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
