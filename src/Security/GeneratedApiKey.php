<?php

declare(strict_types=1);

namespace App\Security;

/**
 * The plain key is shown to the merchant exactly once; only prefix and hash are persisted.
 */
final readonly class GeneratedApiKey
{
    public function __construct(
        public string $plain,
        public string $prefix,
        public string $hash,
    ) {
    }
}
