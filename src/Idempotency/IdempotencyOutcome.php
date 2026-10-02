<?php

declare(strict_types=1);

namespace App\Idempotency;

/**
 * Result of {@see IdempotencyService::begin()}: either replay a stored response or proceed with a lock.
 */
final readonly class IdempotencyOutcome
{
    private function __construct(
        public ?StoredResponse $replay,
        public ?IdempotencyLock $lock,
    ) {
    }

    public static function replay(StoredResponse $response): self
    {
        return new self($response, null);
    }

    public static function proceed(IdempotencyLock $lock): self
    {
        return new self(null, $lock);
    }
}
