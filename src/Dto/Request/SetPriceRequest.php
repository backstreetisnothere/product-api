<?php

declare(strict_types=1);

namespace App\Dto\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(required: ['amount'])]
final readonly class SetPriceRequest
{
    public function __construct(
        #[Assert\PositiveOrZero]
        #[Assert\LessThanOrEqual(1_000_000_000)]
        #[OA\Property(description: 'Price in minor units (cents/kopecks).', example: 199900)]
        public int $amount,
    ) {
    }
}
