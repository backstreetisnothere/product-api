<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Resilience\RedisCircuitBreaker;
use Doctrine\DBAL\Connection;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[OA\Tag(name: 'Operations')]
final class HealthController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RedisCircuitBreaker $redis,
    ) {
    }

    /**
     * Readiness probe: checks PostgreSQL and Redis. Exempt from rate limiting and authentication.
     */
    #[Route('/api/v1/health', name: 'api_health', methods: ['GET'])]
    #[Security(name: null)]
    #[OA\Response(response: 200, description: 'All dependencies are reachable.')]
    #[OA\Response(response: 503, description: 'At least one dependency is unreachable.')]
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn (): bool => false !== $this->connection->fetchOne('SELECT 1')),
            'redis' => $this->check($this->redis->check(...)),
        ];

        $healthy = !\in_array('down', $checks, true);

        return new JsonResponse(
            ['status' => $healthy ? 'ok' : 'degraded', 'checks' => $checks],
            $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
            ['Cache-Control' => 'no-store'],
        );
    }

    /**
     * @param callable(): bool $probe
     */
    private function check(callable $probe): string
    {
        try {
            return $probe() ? 'up' : 'down';
        } catch (\Throwable) {
            return 'down';
        }
    }
}
