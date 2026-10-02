<?php

declare(strict_types=1);

namespace App\Security;

use App\Exception\ApiException;
use App\Resilience\RedisCircuitBreaker;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Brute-force protection for credentials: only FAILED attempts consume budget.
 *
 *  - assertNotBlocked() peeks at the budget (consume(0) never spends a token);
 *  - registerFailure() spends one token after a rejected credential.
 *
 * Legitimate clients never touch the budget, while an attacker guessing keys is cut off after
 * `RATE_LIMIT_AUTH_FAILURES` failures per 15 minutes per IP, before any DB/cache work is done.
 * The guard fails open if Redis is unavailable (availability over strictness; the key space is
 * 192 bits, so guessing is infeasible anyway) and logs the incident.
 */
class BruteForceGuard
{
    public function __construct(
        #[Autowire(service: 'limiter.auth_failure')]
        private readonly RateLimiterFactoryInterface $limiter,
        private readonly LoggerInterface $logger,
        private readonly RedisCircuitBreaker $breaker,
    ) {
    }

    /**
     * @throws ApiException 429 when the IP has exhausted its failure budget
     */
    public function assertNotBlocked(string $ip): void
    {
        if ($this->breaker->isOpen()) {
            return;
        }

        try {
            $limit = $this->limiter->create($ip)->consume(0);
        } catch (\Throwable $e) {
            $this->breaker->recordFailure();
            $this->logger->error('Brute-force guard unavailable, failing open.', ['exception' => $e]);

            return;
        }

        if ($limit->getRemainingTokens() <= 0) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            throw ApiException::tooManyRequests(
                'too_many_failed_attempts',
                'Too many failed authentication attempts. Try again later.',
                $retryAfter,
            );
        }
    }

    public function registerFailure(string $ip): void
    {
        if ($this->breaker->isOpen()) {
            return;
        }

        try {
            $this->limiter->create($ip)->consume(1);
        } catch (\Throwable $e) {
            $this->breaker->recordFailure();
            $this->logger->error('Could not register a failed authentication attempt.', ['exception' => $e]);
        }
    }
}
