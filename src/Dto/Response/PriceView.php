<?php

declare(strict_types=1);

namespace App\Dto\Response;

use App\Entity\ProductPrice;
use OpenApi\Attributes as OA;

final readonly class PriceView
{
    public function __construct(
        #[OA\Property(example: 'RUB')]
        public string $currency,
        #[OA\Property(description: 'Amount in minor units (cents/kopecks).', example: 199900)]
        public int $amount,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function fromEntity(ProductPrice $price): self
    {
        return new self($price->getCurrency(), $price->getAmount(), $price->getUpdatedAt());
    }
}
