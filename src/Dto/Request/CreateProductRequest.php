<?php

declare(strict_types=1);

namespace App\Dto\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(required: ['sku', 'name'])]
final readonly class CreateProductRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 64)]
        #[Assert\Regex(pattern: '/^[A-Za-z0-9._-]+$/', message: 'SKU may contain only letters, digits, dot, underscore and dash.')]
        #[OA\Property(example: 'TSHIRT-BLK-M')]
        public string $sku,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        #[OA\Property(example: 'Black T-shirt, size M')]
        public string $name,
        #[Assert\Length(max: 5000)]
        public ?string $description = null,
    ) {
    }
}
