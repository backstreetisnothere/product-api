<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WarehouseRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: WarehouseRepository::class)]
#[ORM\Table(name: 'warehouses')]
#[ORM\UniqueConstraint(name: 'uniq_warehouses_merchant_code', columns: ['merchant_id', 'code'])]
class Warehouse
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Merchant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Merchant $merchant;

    #[ORM\Column(length: 32)]
    private string $code;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $city;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Merchant $merchant, string $code, string $name, ?string $city)
    {
        $this->id = Uuid::v7();
        $this->merchant = $merchant;
        $this->code = $code;
        $this->name = $name;
        $this->city = $city;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function update(string $name, ?string $city): void
    {
        $this->name = $name;
        $this->city = $city;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMerchant(): Merchant
    {
        return $this->merchant;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
