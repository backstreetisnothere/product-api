<?php

declare(strict_types=1);

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ListProductsQuery
{
    public function __construct(
        #[Assert\Positive]
        #[Assert\LessThanOrEqual(100000)]
        public int $page = 1,
        #[Assert\Range(min: 1, max: 100)]
        public int $perPage = 20,
        #[Assert\Length(max: 100)]
        public ?string $q = null,
        public ?bool $active = null,
    ) {
    }
}
