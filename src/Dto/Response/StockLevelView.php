<?php

declare(strict_types=1);

namespace App\Dto\Response;

use OpenApi\Attributes as OA;

final readonly class StockLevelView
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $productId,
        #[OA\Property(format: 'uuid')]
        public string $warehouseId,
        #[OA\Property(description: 'On-hand quantity after the operation.')]
        public int $quantity,
    ) {
    }
}
