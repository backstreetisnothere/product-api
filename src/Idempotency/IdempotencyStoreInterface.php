<?php

declare(strict_types=1);

namespace App\Idempotency;

interface IdempotencyStoreInterface
{
    public function findResponse(string $scope): ?StoredResponse;

    /**
     * Atomically claims the key for one in-flight request (SET NX + TTL).
     */
    public function acquireLock(string $scope, string $owner): bool;

    /**
     * Releases the claim only if $owner still holds it (compare-and-delete).
     */
    public function releaseLock(string $scope, string $owner): void;

    public function saveResponse(string $scope, StoredResponse $response): void;
}
