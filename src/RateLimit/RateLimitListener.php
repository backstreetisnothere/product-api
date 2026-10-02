<?php

declare(strict_types=1);

namespace App\RateLimit;

use App\Exception\ApiException;
use App\Resilience\RedisCircuitBreaker;
use App\Security\MerchantUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Two layers of rate limiting, both backed by Redis (Symfony RateLimiter, sliding window):
 *
 *  1. per client IP, BEFORE the firewall (priority 20): sheds floods of anonymous / invalid traffic
 *     before any authentication work, DB or cache access happens;
 *  2. per merchant, AFTER the firewall (priority 7): the business quota of an authenticated merchant,
 *     independent of how many IPs the merchant uses.
 *
 * The limiter is fail-open: if Redis is unreachable the request is served (and the incident logged)
 * instead of turning a cache outage into a full API outage.
 */
final class RateLimitListener
{
    private const ATTRIBUTE = '_rate_limit';

    public function __construct(
        #[Autowire(service: 'limiter.api_ip')]
        private readonly RateLimiterFactoryInterface $ipLimiter,
        #[Autowire(service: 'limiter.api_merchant')]
        private readonly RateLimiterFactoryInterface $merchantLimiter,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly LoggerInterface $logger,
        private readonly RedisCircuitBreaker $breaker,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
    public function limitByIp(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || !$this->isLimited($request)) {
            return;
        }

        $this->consume(
            $request,
            $this->ipLimiter,
            $request->getClientIp() ?? 'unknown',
            'ip_rate_limit_exceeded',
            'Too many requests from this IP address.',
        );
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
    public function limitByMerchant(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $user = $this->tokenStorage->getToken()?->getUser();

        if (!$event->isMainRequest() || !$user instanceof MerchantUser) {
            return;
        }

        $this->consume(
            $request,
            $this->merchantLimiter,
            $user->getUserIdentifier(),
            'rate_limit_exceeded',
            'Merchant request quota exceeded.',
        );
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function addHeaders(ResponseEvent $event): void
    {
        $limit = $event->getRequest()->attributes->get(self::ATTRIBUTE);

        if (!$limit instanceof RateLimit) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $remaining = max(0, $limit->getRemainingTokens());

        $headers->set('X-RateLimit-Limit', (string) $limit->getLimit());
        $headers->set('X-RateLimit-Remaining', (string) $remaining);

        // Only meaningful once the budget is spent: the moment a new request will be accepted again.
        if (0 === $remaining) {
            $headers->set('X-RateLimit-Reset', (string) $limit->getRetryAfter()->getTimestamp());
        }
    }

    private function consume(Request $request, RateLimiterFactoryInterface $factory, string $key, string $code, string $message): void
    {
        if ($this->breaker->isOpen()) {
            return;
        }

        try {
            $limit = $factory->create($key)->consume();
        } catch (\Throwable $e) {
            $this->breaker->recordFailure();
            $this->logger->error('Rate limiter unavailable, failing open.', ['exception' => $e]);

            return;
        }

        $request->attributes->set(self::ATTRIBUTE, $limit);

        if (!$limit->isAccepted()) {
            throw ApiException::tooManyRequests(
                $code,
                $message,
                $limit->getRetryAfter()->getTimestamp() - time(),
            );
        }
    }

    /**
     * Only the API is limited; health probes are exempt so that orchestrators are never throttled.
     */
    private function isLimited(Request $request): bool
    {
        $path = $request->getPathInfo();

        return str_starts_with($path, '/api/') && !str_starts_with($path, '/api/v1/health');
    }
}
