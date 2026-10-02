<?php

declare(strict_types=1);

namespace App\Idempotency\Attribute;

/**
 * Marks a controller action as requiring (or merely supporting) the Idempotency-Key header.
 *
 * Any POST/PUT/PATCH of an authenticated merchant that carries the header is handled idempotently;
 * this attribute only adds the ability to make the header mandatory for operations that are NOT
 * naturally idempotent (e.g. "increase stock by 5").
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final readonly class Idempotent
{
    public function __construct(public bool $required = false)
    {
    }
}
