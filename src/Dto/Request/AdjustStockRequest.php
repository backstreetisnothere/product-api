<?php

declare(strict_types=1);

namespace App\Dto\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(required: ['delta'])]
final readonly class AdjustStockRequest
{
    public function __construct(
        #[Assert\NotEqualTo(0, message: 'Delta must not be zero.')]
        #[Assert\Range(min: -100_000_000, max: 100_000_000)]
        #[OA\Property(description: 'Signed change of the on-hand quantity (negative = write-off / sale).', example: -3)]
        public int $delta,
    ) {
    }
}
