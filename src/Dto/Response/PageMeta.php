<?php

declare(strict_types=1);

namespace App\Dto\Response;

final readonly class PageMeta
{
    public function __construct(
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }
}
