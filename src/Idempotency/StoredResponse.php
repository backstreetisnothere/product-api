<?php

declare(strict_types=1);

namespace App\Idempotency;

use Symfony\Component\HttpFoundation\Response;

/**
 * Snapshot of a successful response, replayed verbatim for duplicate requests.
 */
final readonly class StoredResponse
{
    /** Headers worth replaying; everything else (rate limit counters, dates...) is per-request. */
    private const REPLAYED_HEADERS = ['Content-Type', 'Location'];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $fingerprint,
        public int $status,
        public array $headers,
        public string $body,
    ) {
    }

    public static function fromResponse(string $fingerprint, Response $response): self
    {
        $headers = [];
        foreach (self::REPLAYED_HEADERS as $name) {
            if ($response->headers->has($name)) {
                $headers[$name] = (string) $response->headers->get($name);
            }
        }

        return new self($fingerprint, $response->getStatusCode(), $headers, (string) $response->getContent());
    }

    public function toResponse(): Response
    {
        $response = new Response($this->body, $this->status, $this->headers);
        $response->headers->set('Idempotent-Replayed', 'true');

        return $response;
    }

    public function encode(): string
    {
        return json_encode([
            'fingerprint' => $this->fingerprint,
            'status' => $this->status,
            'headers' => $this->headers,
            'body' => base64_encode($this->body),
        ], \JSON_THROW_ON_ERROR);
    }

    public static function decode(string $payload): self
    {
        /** @var array{fingerprint: string, status: int, headers: array<string, string>, body: string} $data */
        $data = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);

        return new self($data['fingerprint'], $data['status'], $data['headers'], (string) base64_decode($data['body'], true));
    }
}
