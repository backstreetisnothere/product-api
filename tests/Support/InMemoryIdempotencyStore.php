<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Idempotency\IdempotencyStoreInterface;
use App\Idempotency\StoredResponse;

class InMemoryIdempotencyStore implements IdempotencyStoreInterface
{
    /** @var array<string, string> */
    private array $locks = [];

    /** @var array<string, StoredResponse> */
    private array $responses = [];

    public function findResponse(string $scope): ?StoredResponse
    {
        return $this->responses[$scope] ?? null;
    }

    public function acquireLock(string $scope, string $owner): bool
    {
        if (isset($this->locks[$scope])) {
            return false;
        }

        $this->locks[$scope] = $owner;

        return true;
    }

    public function releaseLock(string $scope, string $owner): void
    {
        if (($this->locks[$scope] ?? null) === $owner) {
            unset($this->locks[$scope]);
        }
    }

    public function saveResponse(string $scope, StoredResponse $response): void
    {
        $this->responses[$scope] = $response;
    }

    public function isLocked(string $scope): bool
    {
        return isset($this->locks[$scope]);
    }

    /**
     * Simulates "the other request finished between our lookup and our lock attempt".
     */
    public function completeElsewhere(string $scope, StoredResponse $response): void
    {
        $this->responses[$scope] = $response;
    }
}
