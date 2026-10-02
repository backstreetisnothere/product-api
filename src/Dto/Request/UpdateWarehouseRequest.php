<?php

declare(strict_types=1);

namespace App\Dto\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(required: ['name'])]
final readonly class UpdateWarehouseRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 120)]
        public string $name,
        #[Assert\Length(max: 120)]
        public ?string $city = null,
    ) {
    }
}
