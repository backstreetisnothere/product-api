<?php

declare(strict_types=1);

namespace App\Tests\Support;

final readonly class TestMerchant
{
    public function __construct(
        public string $id,
        public string $apiKey,
    ) {
    }
}
