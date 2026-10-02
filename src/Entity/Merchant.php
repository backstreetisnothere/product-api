<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MerchantRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MerchantRepository::class)]
#[ORM\Table(name: 'merchants')]
#[ORM\UniqueConstraint(name: 'uniq_merchants_api_key_prefix', columns: ['api_key_prefix'])]
class Merchant
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    private string $name;

    /** Public, indexed part of the API key used for lookup (never a secret). */
    #[ORM\Column(length: 16)]
    private string $apiKeyPrefix;

    /** HMAC-SHA256 of the full API key (keyed with a server-side pepper). */
    #[ORM\Column(length: 64)]
    private string $apiKeyHash;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, string $apiKeyPrefix, string $apiKeyHash)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->apiKeyPrefix = $apiKeyPrefix;
        $this->apiKeyHash = $apiKeyHash;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getApiKeyPrefix(): string
    {
        return $this->apiKeyPrefix;
    }

    public function getApiKeyHash(): string
    {
        return $this->apiKeyHash;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
