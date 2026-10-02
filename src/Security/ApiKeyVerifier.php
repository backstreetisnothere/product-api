<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\BadCredentialsException;

class ApiKeyVerifier
{
    public function __construct(
        private readonly ApiKeyService $keys,
        private readonly MerchantIdentityProvider $identities,
    ) {
    }

    /**
     * @throws BadCredentialsException for unknown, malformed or deactivated keys
     */
    public function verify(string $plainKey): MerchantUser
    {
        $parts = $this->keys->parse($plainKey);
        $identity = null === $parts ? null : $this->identities->findByApiKeyPrefix($parts->prefix);

        // Always hash and compare, even for unknown prefixes: uniform timing, no key-existence oracle.
        $expected = $identity->apiKeyHash ?? str_repeat('0', 64);
        $matches = hash_equals($expected, $this->keys->hash($plainKey));

        if (null === $identity || !$matches || !$identity->active) {
            throw new BadCredentialsException('Invalid API key.');
        }

        return new MerchantUser($identity->id, $identity->name);
    }
}
