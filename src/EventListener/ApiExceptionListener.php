<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\ApiException;
use App\Http\ProblemResponseFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Turns every exception into a machine-readable problem+json response.
 * Unexpected errors never leak internals: details are logged, clients get a generic 500.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final readonly class ApiExceptionListener
{
    public function __construct(
        private ProblemResponseFactory $problems,
        private LoggerInterface $logger,
        #[Autowire('%kernel.debug%')]
        private bool $debug,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        $event->setResponse(match (true) {
            $exception instanceof ApiException => $this->problems->create(
                $exception->getStatusCode(),
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHeaders(),
                $exception->getExtra(),
            ),
            $exception instanceof HttpExceptionInterface => $this->fromHttpException($exception),
            default => $this->internalError($exception),
        });
    }

    private function fromHttpException(HttpExceptionInterface $exception): Response
    {
        $status = $exception->getStatusCode();
        $previous = $exception->getPrevious();

        if ($previous instanceof ValidationFailedException) {
            $violations = [];
            foreach ($previous->getViolations() as $violation) {
                $violations[] = [
                    'field' => $violation->getPropertyPath(),
                    'message' => (string) $violation->getMessage(),
                ];
            }

            return $this->problems->create(
                $status,
                'validation_failed',
                'The request payload is invalid.',
                $exception->getHeaders(),
                ['violations' => $violations],
            );
        }

        $code = strtolower(str_replace(' ', '_', Response::$statusTexts[$status] ?? 'error'));

        return $this->problems->create(
            $status,
            $code,
            $status >= Response::HTTP_INTERNAL_SERVER_ERROR ? 'The server could not process the request.' : $exception->getMessage(),
            $exception->getHeaders(),
        );
    }

    private function internalError(\Throwable $exception): Response
    {
        $this->logger->error('Unhandled exception: '.$exception->getMessage(), ['exception' => $exception]);

        return $this->problems->create(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            'internal_error',
            $this->debug ? $exception->getMessage() : 'An unexpected error occurred.',
        );
    }
}
