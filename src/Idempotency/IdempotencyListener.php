<?php

declare(strict_types=1);

namespace App\Idempotency;

use App\Exception\ApiException;
use App\Idempotency\Attribute\Idempotent;
use App\Resilience\RedisCircuitBreaker;
use App\Security\MerchantUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * HTTP glue for {@see IdempotencyService}.
 *
 * Runs on kernel.controller (after routing, firewall and rate limiting, before argument resolution):
 *  - a replay swaps the controller for a closure returning the stored response, so neither payload
 *    validation nor the business logic run again;
 *  - otherwise the lock is kept in a request attribute and settled on kernel.response.
 *
 * If Redis is unavailable the listener FAILS CLOSED with 503 for requests that sent an Idempotency-Key:
 * the client explicitly asked for exactly-once semantics, and silently executing a possibly duplicated
 * mutation would be worse than asking it to retry.
 */
final class IdempotencyListener
{
    private const LOCK_ATTRIBUTE = '_idempotency_lock';
    private const KEY_PATTERN = '/^[A-Za-z0-9._:-]{8,128}$/';
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH'];

    public function __construct(
        private readonly IdempotencyService $service,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly LoggerInterface $logger,
        private readonly RedisCircuitBreaker $breaker,
    ) {
    }

    #[AsEventListener(event: KernelEvents::CONTROLLER)]
    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || !\in_array($request->getMethod(), self::MUTATING_METHODS, true)) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof MerchantUser) {
            return;
        }

        $key = $request->headers->get('Idempotency-Key');

        if (null === $key) {
            /** @var list<Idempotent> $attributes */
            $attributes = $event->getAttributes(Idempotent::class);
            if ([] !== $attributes && $attributes[0]->required) {
                throw ApiException::badRequest('idempotency_key_required', 'The Idempotency-Key header is required for this operation.');
            }

            return;
        }

        if (1 !== preg_match(self::KEY_PATTERN, $key)) {
            throw ApiException::badRequest('idempotency_key_invalid', 'Idempotency-Key must be 8-128 characters of A-Z, a-z, 0-9, ".", "_", ":" or "-".');
        }

        if ($this->breaker->isOpen()) {
            throw $this->unavailable();
        }

        $scope = $user->getUserIdentifier().':'.hash('sha256', $key);

        try {
            $outcome = $this->service->begin($scope, $this->fingerprint($request));
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->breaker->recordFailure();
            $this->logger->error('Idempotency store unavailable.', ['exception' => $e]);

            throw $this->unavailable();
        }

        if (null !== $outcome->replay) {
            $response = $outcome->replay->toResponse();
            $event->setController(static fn () => $response);

            return;
        }

        $request->attributes->set(self::LOCK_ATTRIBUTE, $outcome->lock);
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        $lock = $event->getRequest()->attributes->get(self::LOCK_ATTRIBUTE);

        if (!$lock instanceof IdempotencyLock) {
            return;
        }

        $event->getRequest()->attributes->remove(self::LOCK_ATTRIBUTE);

        try {
            $this->service->complete($lock, $event->getResponse());
        } catch (\Throwable $e) {
            // The operation already happened; never turn a committed success into an error.
            $this->breaker->recordFailure();
            $this->logger->error('Could not store the idempotent response.', ['exception' => $e]);
        }
    }

    private function unavailable(): ApiException
    {
        return ApiException::serviceUnavailable('idempotency_unavailable', 'Idempotency cannot be guaranteed right now. Retry shortly.');
    }

    /**
     * Identifies "the same request": method + path + query + raw body.
     */
    private function fingerprint(Request $request): string
    {
        return hash('sha256', implode("\n", [
            $request->getMethod(),
            $request->getPathInfo(),
            $request->getQueryString() ?? '',
            $request->getContent(),
        ]));
    }
}
