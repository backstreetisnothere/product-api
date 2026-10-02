<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Domain/application error that maps 1:1 to an HTTP problem response.
 * Rendered by {@see \App\EventListener\ApiExceptionListener}.
 */
final class ApiException extends \RuntimeException implements HttpExceptionInterface
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $extra
     */
    private function __construct(
        private readonly int $statusCode,
        private readonly string $errorCode,
        string $message,
        private readonly array $headers = [],
        private readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function badRequest(string $code, string $message): self
    {
        return new self(Response::HTTP_BAD_REQUEST, $code, $message);
    }

    public static function unauthorized(string $message = 'Authentication is required or the credentials are invalid.'): self
    {
        return new self(
            Response::HTTP_UNAUTHORIZED,
            'unauthorized',
            $message,
            ['WWW-Authenticate' => 'Bearer realm="Seller API"'],
        );
    }

    public static function notFound(string $resource): self
    {
        return new self(Response::HTTP_NOT_FOUND, 'not_found', \sprintf('%s not found.', $resource));
    }

    public static function conflict(string $code, string $message, int $retryAfter = 0): self
    {
        return new self(
            Response::HTTP_CONFLICT,
            $code,
            $message,
            $retryAfter > 0 ? ['Retry-After' => (string) $retryAfter] : [],
        );
    }

    public static function unprocessable(string $code, string $message): self
    {
        return new self(Response::HTTP_UNPROCESSABLE_ENTITY, $code, $message);
    }

    public static function tooManyRequests(string $code, string $message, int $retryAfter): self
    {
        return new self(
            Response::HTTP_TOO_MANY_REQUESTS,
            $code,
            $message,
            ['Retry-After' => (string) max(1, $retryAfter)],
        );
    }

    public static function serviceUnavailable(string $code, string $message, int $retryAfter = 1): self
    {
        return new self(
            Response::HTTP_SERVICE_UNAVAILABLE,
            $code,
            $message,
            ['Retry-After' => (string) max(1, $retryAfter)],
        );
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtra(): array
    {
        return $this->extra;
    }
}
