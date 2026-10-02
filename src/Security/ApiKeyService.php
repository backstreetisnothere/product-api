<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * API key format: sk_<12 hex prefix>_<48 hex secret>  (192 bits of entropy in the secret).
 *
 * The prefix is a public lookup handle (indexed column); the whole key is stored only as
 * HMAC-SHA256 keyed with a server-side pepper. Because the key is high-entropy random data a fast
 * keyed hash is appropriate (a slow password hash would only burn CPU at 1k RPS), and a leaked
 * database alone is not enough to verify or forge keys.
 */
class ApiKeyService
{
    private const FORMAT = '/^sk_([a-f0-9]{12})_([a-f0-9]{48})$/';

    public function __construct(
        #[Autowire(env: 'API_KEY_PEPPER')]
        private readonly string $pepper,
    ) {
    }

    public function generate(): GeneratedApiKey
    {
        $prefix = bin2hex(random_bytes(6));
        $plain = \sprintf('sk_%s_%s', $prefix, bin2hex(random_bytes(24)));

        return new GeneratedApiKey($plain, $prefix, $this->hash($plain));
    }

    public function hash(string $plainKey): string
    {
        return hash_hmac('sha256', $plainKey, $this->pepper);
    }

    public function parse(string $plainKey): ?ApiKeyParts
    {
        if (1 !== preg_match(self::FORMAT, $plainKey, $matches)) {
            return null;
        }

        return new ApiKeyParts($matches[1], $matches[2]);
    }
}
