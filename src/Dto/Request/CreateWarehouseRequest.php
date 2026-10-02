<?php

declare(strict_types=1);

namespace App\Dto\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(required: ['code', 'name'])]
final readonly class CreateWarehouseRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: '/^[A-Z0-9_-]{2,32}$/', message: 'Code must be 2-32 characters: A-Z, 0-9, underscore or dash.')]
        #[OA\Property(example: 'MSK-01')]
        public string $code,
        #[Assert\NotBlank]
        #[Assert\Length(max: 120)]
        #[OA\Property(example: 'Moscow main warehouse')]
        public string $name,
        #[Assert\Length(max: 120)]
        #[OA\Property(example: 'Moscow')]
        public ?string $city = null,
    ) {
    }
}
