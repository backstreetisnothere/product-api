<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiException;
use App\Http\ViewNormalizer;
use App\Security\ApiKeyVerifier;
use App\Security\BruteForceGuard;
use App\Security\JwtTokenService;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

class AuthService
{
    public function __construct(
        private readonly ApiKeyVerifier $apiKeys,
        private readonly JwtTokenService $jwt,
        private readonly BruteForceGuard $guard,
        private readonly ViewNormalizer $views,
    ) {
    }

    /**
     * Exchanges a valid API key for a short-lived JWT.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException 401 for bad credentials, 429 when the IP is temporarily blocked
     */
    public function issueToken(string $apiKey, string $clientIp): array
    {
        $this->guard->assertNotBlocked($clientIp);

        try {
            $merchant = $this->apiKeys->verify($apiKey);
        } catch (BadCredentialsException) {
            $this->guard->registerFailure($clientIp);

            throw ApiException::unauthorized('The API key is invalid.');
        }

        return $this->views->normalize($this->jwt->issue($merchant->id));
    }
}
