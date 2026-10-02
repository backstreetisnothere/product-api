<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Repository\StockRepository;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(required: ['quantity'])]
final readonly class SetStockRequest
{
    public function __construct(
        #[Assert\PositiveOrZero]
        #[Assert\LessThanOrEqual(StockRepository::MAX_QUANTITY)]
        #[OA\Property(description: 'Absolute on-hand quantity.', example: 120)]
        public int $quantity,
    ) {
    }
}
