<?php

declare(strict_types=1);

namespace App\Dto\Response;

use OpenApi\Attributes as OA;

final readonly class WarehouseStockView
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $warehouseId,
        #[OA\Property(example: 'MSK-01')]
        public string $code,
        public string $name,
        public int $quantity,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
