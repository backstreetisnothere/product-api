<?php

declare(strict_types=1);

namespace App\Idempotency;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

class RedisIdempotencyStore implements IdempotencyStoreInterface
{
    private const KEY_PREFIX = 'seller-api:idem:';

    /** Releases the lock only when it still belongs to the caller (prevents freeing someone else's lock). */
    private const RELEASE_SCRIPT = <<<'LUA'
        if redis.call('get', KEYS[1]) == ARGV[1] then
            return redis.call('del', KEYS[1])
        end
        return 0
        LUA;

    public function __construct(
        #[Autowire(service: 'app.redis')]
        private readonly \Redis $redis,
        private readonly int $lockTtlSeconds = 30,
        private readonly int $responseTtlSeconds = 86400,
    ) {
    }

    public function findResponse(string $scope): ?StoredResponse
    {
        $payload = $this->redis->get($this->key($scope, 'response'));

        return \is_string($payload) ? StoredResponse::decode($payload) : null;
    }

    public function acquireLock(string $scope, string $owner): bool
    {
        // NX: only if absent; EX: a crashed worker can never block a key forever.
        return true === $this->redis->set($this->key($scope, 'lock'), $owner, ['nx', 'ex' => $this->lockTtlSeconds]);
    }

    public function releaseLock(string $scope, string $owner): void
    {
        $this->redis->eval(self::RELEASE_SCRIPT, [$this->key($scope, 'lock'), $owner], 1);
    }

    public function saveResponse(string $scope, StoredResponse $response): void
    {
        $this->redis->set($this->key($scope, 'response'), $response->encode(), ['ex' => $this->responseTtlSeconds]);
    }

    private function key(string $scope, string $type): string
    {
        return self::KEY_PREFIX.$scope.':'.$type;
    }
}
