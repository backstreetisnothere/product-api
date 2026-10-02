<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Dto\Request\AdjustStockRequest;
use App\Dto\Request\SetStockRequest;
use App\Dto\Response\StockLevelView;
use App\Dto\Response\StockView;
use App\Idempotency\Attribute\Idempotent;
use App\Security\MerchantUser;
use App\Service\StockService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[AsController]
#[Route('/api/v1/products/{productId}/stock', requirements: ['productId' => Requirement::UUID])]
#[OA\Tag(name: 'Stock')]
final class StockController
{
    public function __construct(private readonly StockService $stock)
    {
    }

    /**
     * Stock of a product in all warehouses (cached, short TTL).
     */
    #[Route('', name: 'api_stock_get', methods: ['GET'])]
    #[OA\Response(response: 200, description: 'Stock by warehouse with the total.', content: new OA\JsonContent(ref: new Model(type: StockView::class)))]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    public function get(#[CurrentUser] MerchantUser $user, string $productId): JsonResponse
    {
        return new JsonResponse($this->stock->get($user->id, $productId));
    }

    /**
     * Set the absolute on-hand quantity of a product in a warehouse.
     */
    #[Route('/{warehouseId}', name: 'api_stock_set', requirements: ['warehouseId' => Requirement::UUID], methods: ['PUT'])]
    #[Idempotent]
    #[OA\Parameter(ref: '#/components/parameters/IdempotencyKey')]
    #[OA\Response(response: 200, description: 'The resulting stock level.', content: new OA\JsonContent(ref: new Model(type: StockLevelView::class)))]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    #[OA\Response(response: 422, ref: '#/components/responses/ValidationFailed')]
    public function set(#[CurrentUser] MerchantUser $user, string $productId, string $warehouseId, #[MapRequestPayload] SetStockRequest $dto): JsonResponse
    {
        return new JsonResponse($this->stock->set($user->id, $productId, $warehouseId, $dto->quantity));
    }

    /**
     * Adjust stock by a signed delta (receipt, sale, write-off).
     *
     * Requires an Idempotency-Key: a retried adjustment must never be applied twice. The change is one
     * atomic SQL statement and can never drive the stock below zero.
     */
    #[Route('/{warehouseId}/adjustments', name: 'api_stock_adjust', requirements: ['warehouseId' => Requirement::UUID], methods: ['POST'])]
    #[Idempotent(required: true)]
    #[OA\Parameter(ref: '#/components/parameters/IdempotencyKeyRequired')]
    #[OA\Response(response: 200, description: 'The resulting stock level.', content: new OA\JsonContent(ref: new Model(type: StockLevelView::class)))]
    #[OA\Response(response: 400, description: 'Missing or malformed Idempotency-Key.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    #[OA\Response(response: 409, ref: '#/components/responses/Conflict')]
    #[OA\Response(response: 422, ref: '#/components/responses/ValidationFailed')]
    public function adjust(#[CurrentUser] MerchantUser $user, string $productId, string $warehouseId, #[MapRequestPayload] AdjustStockRequest $dto): JsonResponse
    {
        return new JsonResponse($this->stock->adjust($user->id, $productId, $warehouseId, $dto->delta));
    }
}
