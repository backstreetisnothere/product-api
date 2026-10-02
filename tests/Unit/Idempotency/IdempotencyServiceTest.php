<?php

declare(strict_types=1);

namespace App\Tests\Unit\Idempotency;

use App\Exception\ApiException;
use App\Idempotency\IdempotencyService;
use App\Idempotency\StoredResponse;
use App\Tests\Support\InMemoryIdempotencyStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class IdempotencyServiceTest extends TestCase
{
    private InMemoryIdempotencyStore $store;
    private IdempotencyService $service;

    protected function setUp(): void
    {
        $this->store = new InMemoryIdempotencyStore();
        $this->service = new IdempotencyService($this->store);
    }

    public function testNewKeyProceedsAndHoldsTheLockUntilCompletion(): void
    {
        $outcome = $this->service->begin('scope', 'fp');

        self::assertNull($outcome->replay);
        self::assertNotNull($outcome->lock);
        self::assertTrue($this->store->isLocked('scope'));
    }

    public function testSuccessfulResponseIsStoredAndReplayed(): void
    {
        $lock = $this->service->begin('scope', 'fp')->lock;
        self::assertNotNull($lock);

        $this->service->complete($lock, new JsonResponse(['id' => 1], Response::HTTP_CREATED, ['Location' => '/things/1']));

        self::assertFalse($this->store->isLocked('scope'), 'The lock is released after completion.');

        $replay = $this->service->begin('scope', 'fp')->replay;
        self::assertNotNull($replay);

        $response = $replay->toResponse();
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertSame('{"id":1}', $response->getContent());
        self::assertSame('/things/1', $response->headers->get('Location'));
        self::assertSame('true', $response->headers->get('Idempotent-Replayed'));
    }

    public function testFailedResponsesAreNotStored(): void
    {
        foreach ([Response::HTTP_CONFLICT, Response::HTTP_UNPROCESSABLE_ENTITY, Response::HTTP_INTERNAL_SERVER_ERROR] as $status) {
            $scope = 'scope-'.$status;
            $lock = $this->service->begin($scope, 'fp')->lock;
            self::assertNotNull($lock);

            $this->service->complete($lock, new JsonResponse(['error' => true], $status));

            self::assertNull($this->store->findResponse($scope), \sprintf('%d must not be stored.', $status));
            self::assertFalse($this->store->isLocked($scope), 'The lock is released so the client can retry.');
        }
    }

    public function testDuplicateWhileInFlightIsAConflict(): void
    {
        $this->service->begin('scope', 'fp');

        try {
            $this->service->begin('scope', 'fp');
            self::fail('A parallel duplicate must be rejected.');
        } catch (ApiException $e) {
            self::assertSame(Response::HTTP_CONFLICT, $e->getStatusCode());
            self::assertSame('idempotency_request_in_progress', $e->getErrorCode());
            self::assertSame(['Retry-After' => '1'], $e->getHeaders());
        }
    }

    public function testSameKeyWithDifferentPayloadIsRejected(): void
    {
        $lock = $this->service->begin('scope', 'fp-1')->lock;
        self::assertNotNull($lock);
        $this->service->complete($lock, new JsonResponse([], Response::HTTP_OK));

        try {
            $this->service->begin('scope', 'fp-2');
            self::fail('Reusing a key with another payload must be rejected.');
        } catch (ApiException $e) {
            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $e->getStatusCode());
            self::assertSame('idempotency_key_reused', $e->getErrorCode());
        }
    }

    public function testRequestThatLosesTheRaceButFindsTheFinishedResponseReplaysIt(): void
    {
        // Request A completed after B's first lookup but before B's lock attempt: A's lock is still
        // set while its response is already saved.
        $this->store->acquireLock('scope', 'request-a');
        $this->store->completeElsewhere('scope', new StoredResponse('fp', Response::HTTP_OK, ['Content-Type' => 'application/json'], '{"ok":true}'));

        $outcome = $this->service->begin('scope', 'fp');

        self::assertNotNull($outcome->replay);
        self::assertNull($outcome->lock);
    }

    public function testLockIsReleasedWhenTheDoubleCheckFindsAResponse(): void
    {
        $store = new class extends InMemoryIdempotencyStore {
            private int $lookups = 0;

            public function findResponse(string $scope): ?StoredResponse
            {
                // Miss on the first lookup, hit on the double-check after the lock was acquired.
                return 1 === ++$this->lookups
                    ? null
                    : new StoredResponse('fp', Response::HTTP_OK, [], '{}');
            }
        };

        $outcome = (new IdempotencyService($store))->begin('scope', 'fp');

        self::assertNotNull($outcome->replay);
        self::assertFalse($store->isLocked('scope'), 'A replay must not leave the lock behind.');
    }

    public function testOnlyTheOwnerCanReleaseTheLock(): void
    {
        $this->store->acquireLock('scope', 'owner');
        $this->store->releaseLock('scope', 'somebody-else');

        self::assertTrue($this->store->isLocked('scope'));
    }
}
