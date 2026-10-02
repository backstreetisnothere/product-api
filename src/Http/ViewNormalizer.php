<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Turns read-model DTOs into plain arrays (snake_case keys, ISO-8601 dates).
 * Arrays are what gets cached in Redis: they survive code changes between deploys,
 * unlike PHP-serialized objects.
 */
class ViewNormalizer
{
    public function __construct(private readonly NormalizerInterface $normalizer)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(object $view): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->normalizer->normalize($view);

        return $data;
    }
}
