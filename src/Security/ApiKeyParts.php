<?php

declare(strict_types=1);

namespace App\Security;

final readonly class ApiKeyParts
{
    public function __construct(
        public string $prefix,
        public string $secret,
    ) {
    }
}
