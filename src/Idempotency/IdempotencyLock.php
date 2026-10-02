<?php

declare(strict_types=1);

namespace App\Idempotency;

/**
 * Proof that the current request is the one allowed to execute the operation for this key.
 */
final readonly class IdempotencyLock
{
    public function __construct(
        public string $scope,
        public string $owner,
        public string $fingerprint,
    ) {
    }
}
