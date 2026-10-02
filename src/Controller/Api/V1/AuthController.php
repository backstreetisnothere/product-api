<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Dto\Request\IssueTokenRequest;
use App\Dto\Response\TokenView;
use App\Service\AuthService;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/v1/auth')]
#[OA\Tag(name: 'Authentication')]
final class AuthController
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    /**
     * Exchange an API key for a short-lived JWT.
     *
     * Public endpoint. Failed attempts count against a per-IP budget; once it is spent the IP is blocked
     * with 429 for the rest of the 15 minute window.
     */
    #[Route('/token', name: 'api_auth_token', methods: ['POST'])]
    #[Security(name: null)]
    #[OA\Response(response: 200, description: 'Token issued.', content: new OA\JsonContent(ref: new Model(type: TokenView::class)))]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 422, ref: '#/components/responses/ValidationFailed')]
    #[OA\Response(response: 429, ref: '#/components/responses/TooManyRequests')]
    public function token(Request $request, #[MapRequestPayload] IssueTokenRequest $dto): JsonResponse
    {
        return new JsonResponse($this->auth->issueToken($dto->apiKey, $request->getClientIp() ?? 'unknown'));
    }
}
