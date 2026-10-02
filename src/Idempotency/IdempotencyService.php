<?php

declare(strict_types=1);

namespace App\Idempotency;

use App\Exception\ApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency protocol (modelled after the IETF "Idempotency-Key" draft / Stripe):
 *
 *  1. same key, completed before          -> replay the stored response (fingerprint must match, else 422);
 *  2. same key, another request in flight -> 409 + Retry-After (the client retries later and gets a replay);
 *  3. new key                             -> claim it with an atomic SET NX, run the handler, store the
 *                                            response, release the claim.
 *
 * Only successful (2xx) responses are stored. A failed request (4xx/5xx) produced no side effects
 * (every handler is transactional), so the client may safely retry and will re-execute it.
 *
 * Race closed by double-checking: the response is saved BEFORE the lock is released, so a request that
 * acquires the lock re-reads the response key; if the previous owner finished in between, it finds the
 * response and replays it instead of executing the operation twice.
 */
class IdempotencyService
{
    public function __construct(private readonly IdempotencyStoreInterface $store)
    {
    }

    /**
     * @throws ApiException 409 when a duplicate is in flight, 422 when the key was used with another payload
     */
    public function begin(string $scope, string $fingerprint): IdempotencyOutcome
    {
        $replay = $this->lookup($scope, $fingerprint);
        if (null !== $replay) {
            return IdempotencyOutcome::replay($replay);
        }

        $owner = bin2hex(random_bytes(16));

        if (!$this->store->acquireLock($scope, $owner)) {
            // The other request may have completed between our lookup and the failed lock attempt.
            $replay = $this->lookup($scope, $fingerprint);
            if (null !== $replay) {
                return IdempotencyOutcome::replay($replay);
            }

            throw ApiException::conflict(
                'idempotency_request_in_progress',
                'A request with this Idempotency-Key is still being processed. Retry shortly.',
                retryAfter: 1,
            );
        }

        try {
            $replay = $this->lookup($scope, $fingerprint);
        } catch (\Throwable $e) {
            $this->store->releaseLock($scope, $owner);

            throw $e;
        }

        if (null !== $replay) {
            $this->store->releaseLock($scope, $owner);

            return IdempotencyOutcome::replay($replay);
        }

        return IdempotencyOutcome::proceed(new IdempotencyLock($scope, $owner, $fingerprint));
    }

    public function complete(IdempotencyLock $lock, Response $response): void
    {
        try {
            if ($response->isSuccessful()) {
                $this->store->saveResponse($lock->scope, StoredResponse::fromResponse($lock->fingerprint, $response));
            }
        } finally {
            $this->store->releaseLock($lock->scope, $lock->owner);
        }
    }

    private function lookup(string $scope, string $fingerprint): ?StoredResponse
    {
        $stored = $this->store->findResponse($scope);

        if (null === $stored) {
            return null;
        }

        if (!hash_equals($stored->fingerprint, $fingerprint)) {
            throw ApiException::unprocessable(
                'idempotency_key_reused',
                'This Idempotency-Key was already used with a different request payload.',
            );
        }

        return $stored;
    }
}
