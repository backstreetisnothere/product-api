<?php

declare(strict_types=1);

namespace App\Dto\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(required: ['name', 'active'])]
final readonly class UpdateProductRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $name,
        public bool $active,
        #[Assert\Length(max: 5000)]
        public ?string $description = null,
    ) {
    }
}
