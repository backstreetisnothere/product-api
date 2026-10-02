<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds RFC 9457 "problem details" responses. Shared by the exception listener
 * and by the authenticator (which must answer without throwing).
 */
final class ProblemResponseFactory
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $extra
     */
    public function create(int $status, string $code, string $detail, array $headers = [], array $extra = []): JsonResponse
    {
        $body = [
            'type' => 'urn:seller-api:error:'.$code,
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => $detail,
            'code' => $code,
        ] + $extra;

        $response = new JsonResponse($body, $status, $headers);
        $response->headers->set('Content-Type', 'application/problem+json');

        return $response;
    }
}
