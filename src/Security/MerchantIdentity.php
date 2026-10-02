<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Merchant;

/**
 * Cache-friendly projection of a merchant used on the authentication hot path,
 * so that authenticating a request usually costs zero database queries.
 */
final readonly class MerchantIdentity
{
    public function __construct(
        public string $id,
        public string $name,
        public string $apiKeyHash,
        public bool $active,
    ) {
    }

    public static function fromEntity(Merchant $merchant): self
    {
        return new self(
            $merchant->getId()->toRfc4122(),
            $merchant->getName(),
            $merchant->getApiKeyHash(),
            $merchant->isActive(),
        );
    }

    /**
     * @return array{id: string, name: string, api_key_hash: string, active: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'api_key_hash' => $this->apiKeyHash,
            'active' => $this->active,
        ];
    }

    /**
     * @param array{id: string, name: string, api_key_hash: string, active: bool} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['name'], $data['api_key_hash'], $data['active']);
    }
}
