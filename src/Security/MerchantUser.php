<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

final readonly class MerchantUser implements UserInterface
{
    /** @var non-empty-string */
    public string $id;

    public function __construct(
        string $id,
        public string $name,
    ) {
        if ('' === $id) {
            throw new \InvalidArgumentException('A merchant user needs a non-empty id.');
        }

        $this->id = $id;
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        return $this->id;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_MERCHANT'];
    }

    public function eraseCredentials(): void
    {
        // Immutable, holds no credentials.
    }
}
