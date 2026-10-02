<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Dto\Request\SetPriceRequest;
use App\Dto\Response\PriceView;
use App\Idempotency\Attribute\Idempotent;
use App\Security\MerchantUser;
use App\Service\PriceService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[AsController]
#[Route('/api/v1/products/{productId}/prices', requirements: ['productId' => Requirement::UUID])]
#[OA\Tag(name: 'Prices')]
final class PriceController
{
    public function __construct(private readonly PriceService $prices)
    {
    }

    /**
     * List prices of a product (cached together with the product).
     */
    #[Route('', name: 'api_prices_list', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: 'Prices by currency.',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: new Model(type: PriceView::class))),
        ]),
    )]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    public function list(#[CurrentUser] MerchantUser $user, string $productId): JsonResponse
    {
        return new JsonResponse($this->prices->list($user->id, $productId));
    }

    /**
     * Set the price of a product in one currency (create or replace).
     */
    #[Route('/{currency}', name: 'api_prices_set', requirements: ['currency' => '[A-Z]{3}'], methods: ['PUT'])]
    #[Idempotent]
    #[OA\Parameter(ref: '#/components/parameters/IdempotencyKey')]
    #[OA\Parameter(name: 'currency', in: 'path', required: true, description: 'ISO 4217 code.', schema: new OA\Schema(type: 'string', example: 'RUB'))]
    #[OA\Response(response: 200, description: 'The stored price.', content: new OA\JsonContent(ref: new Model(type: PriceView::class)))]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    #[OA\Response(response: 422, ref: '#/components/responses/ValidationFailed')]
    public function set(#[CurrentUser] MerchantUser $user, string $productId, string $currency, #[MapRequestPayload] SetPriceRequest $dto): JsonResponse
    {
        return new JsonResponse($this->prices->set($user->id, $productId, $currency, $dto->amount));
    }

    /**
     * Remove the price of a product in one currency.
     */
    #[Route('/{currency}', name: 'api_prices_delete', requirements: ['currency' => '[A-Z]{3}'], methods: ['DELETE'])]
    #[OA\Parameter(name: 'currency', in: 'path', required: true, description: 'ISO 4217 code.', schema: new OA\Schema(type: 'string', example: 'RUB'))]
    #[OA\Response(response: 204, description: 'Deleted.')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    public function delete(#[CurrentUser] MerchantUser $user, string $productId, string $currency): Response
    {
        $this->prices->delete($user->id, $productId, $currency);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
