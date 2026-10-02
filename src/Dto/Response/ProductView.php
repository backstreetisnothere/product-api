<?php

declare(strict_types=1);

namespace App\Dto\Response;

use App\Entity\Product;
use App\Entity\ProductPrice;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

final readonly class ProductView
{
    /**
     * @param list<PriceView> $prices
     */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(example: 'TSHIRT-BLK-M')]
        public string $sku,
        public string $name,
        public ?string $description,
        public bool $active,
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: PriceView::class)))]
        public array $prices,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param list<ProductPrice> $prices
     */
    public static function fromEntity(Product $product, array $prices): self
    {
        return new self(
            $product->getId()->toRfc4122(),
            $product->getSku(),
            $product->getName(),
            $product->getDescription(),
            $product->isActive(),
            array_map(PriceView::fromEntity(...), $prices),
            $product->getCreatedAt(),
            $product->getUpdatedAt(),
        );
    }
}
