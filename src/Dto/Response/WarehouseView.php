<?php

declare(strict_types=1);

namespace App\Dto\Response;

use App\Entity\Warehouse;
use OpenApi\Attributes as OA;

final readonly class WarehouseView
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(example: 'MSK-01')]
        public string $code,
        public string $name,
        public ?string $city,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function fromEntity(Warehouse $warehouse): self
    {
        return new self(
            $warehouse->getId()->toRfc4122(),
            $warehouse->getCode(),
            $warehouse->getName(),
            $warehouse->getCity(),
            $warehouse->getCreatedAt(),
        );
    }
}
