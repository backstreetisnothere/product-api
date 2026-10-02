<?php

declare(strict_types=1);

namespace App\Cache;

/**
 * Mutable record of what happened while a cache miss was being resolved, so that
 * {@see CatalogCache} can tell "the loader failed" apart from "the cache failed".
 */
final class LoadState
{
    public bool $loaded = false;
    public mixed $value = null;
    public ?\Throwable $failure = null;
}
