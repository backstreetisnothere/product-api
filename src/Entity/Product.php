<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'products')]
#[ORM\UniqueConstraint(name: 'uniq_products_merchant_sku', columns: ['merchant_id', 'sku'])]
#[ORM\Index(name: 'idx_products_merchant_created', columns: ['merchant_id', 'created_at'])]
class Product
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Merchant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Merchant $merchant;

    #[ORM\Column(length: 64)]
    private string $sku;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Merchant $merchant, string $sku, string $name, ?string $description)
    {
        $this->id = Uuid::v7();
        $this->merchant = $merchant;
        $this->sku = $sku;
        $this->name = $name;
        $this->description = $description;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function update(string $name, ?string $description, bool $active): void
    {
        $this->name = $name;
        $this->description = $description;
        $this->active = $active;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMerchant(): Merchant
    {
        return $this->merchant;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
