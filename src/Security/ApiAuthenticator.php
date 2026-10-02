<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\ProblemResponseFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Stateless authenticator supporting two credential types:
 *
 *   X-API-Key: sk_<prefix>_<secret>          machine-to-machine, verified against the merchant identity
 *   Authorization: Bearer <jwt>              short-lived token obtained from POST /api/v1/auth/token
 *
 * Brute force: a peek at the per-IP failure budget happens BEFORE any credential is looked up;
 * every rejected credential spends one token of that budget (see {@see BruteForceGuard}).
 */
final class ApiAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly ApiKeyVerifier $apiKeys,
        private readonly JwtTokenService $jwt,
        private readonly BruteForceGuard $guard,
        private readonly ProblemResponseFactory $problems,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->headers->has('X-API-Key') || null !== $this->bearerToken($request);
    }

    public function authenticate(Request $request): Passport
    {
        $this->guard->assertNotBlocked($this->clientIp($request));

        $apiKey = $request->headers->get('X-API-Key');
        if (null !== $apiKey) {
            // The badge identifier is only the public prefix: the secret never reaches logs or tokens.
            return new SelfValidatingPassport(
                new UserBadge($this->publicIdentifier($apiKey), fn (): MerchantUser => $this->apiKeys->verify($apiKey)),
            );
        }

        $merchantId = $this->jwt->parse((string) $this->bearerToken($request));

        // Loaded through the firewall's user provider, which also rejects deactivated merchants.
        return new SelfValidatingPassport(new UserBadge($merchantId));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $this->guard->registerFailure($this->clientIp($request));

        return $this->unauthorized();
    }

    /**
     * Called when a protected resource is requested without any credentials.
     */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->unauthorized();
    }

    private function unauthorized(): Response
    {
        return $this->problems->create(
            Response::HTTP_UNAUTHORIZED,
            'unauthorized',
            'Authentication is required or the credentials are invalid.',
            ['WWW-Authenticate' => 'Bearer realm="Seller API"'],
        );
    }

    private function bearerToken(Request $request): ?string
    {
        $header = (string) $request->headers->get('Authorization', '');

        return str_starts_with($header, 'Bearer ') ? trim(substr($header, 7)) : null;
    }

    private function clientIp(Request $request): string
    {
        return $request->getClientIp() ?? 'unknown';
    }

    /**
     * "sk_" + 12 hex chars: the non-secret part of the key.
     */
    private function publicIdentifier(string $apiKey): string
    {
        return substr($apiKey, 0, 15);
    }
}
