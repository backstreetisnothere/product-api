<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Dto\Request\CreateWarehouseRequest;
use App\Dto\Request\UpdateWarehouseRequest;
use App\Dto\Response\WarehouseListView;
use App\Dto\Response\WarehouseView;
use App\Idempotency\Attribute\Idempotent;
use App\Security\MerchantUser;
use App\Service\WarehouseService;
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
#[Route('/api/v1/warehouses')]
#[OA\Tag(name: 'Warehouses')]
final class WarehouseController
{
    public function __construct(private readonly WarehouseService $warehouses)
    {
    }

    /**
     * List warehouses (cached).
     */
    #[Route('', name: 'api_warehouses_list', methods: ['GET'])]
    #[OA\Response(response: 200, description: 'All warehouses of the merchant.', content: new OA\JsonContent(ref: new Model(type: WarehouseListView::class)))]
    public function list(#[CurrentUser] MerchantUser $user): JsonResponse
    {
        return new JsonResponse($this->warehouses->list($user->id));
    }

    /**
     * Create a warehouse.
     */
    #[Route('', name: 'api_warehouses_create', methods: ['POST'])]
    #[Idempotent]
    #[OA\Parameter(ref: '#/components/parameters/IdempotencyKey')]
    #[OA\Response(response: 201, description: 'Warehouse created.', content: new OA\JsonContent(ref: new Model(type: WarehouseView::class)))]
    #[OA\Response(response: 409, ref: '#/components/responses/Conflict')]
    #[OA\Response(response: 422, ref: '#/components/responses/ValidationFailed')]
    public function create(#[CurrentUser] MerchantUser $user, #[MapRequestPayload] CreateWarehouseRequest $dto): JsonResponse
    {
        $warehouse = $this->warehouses->create($user->id, $dto);

        return new JsonResponse($warehouse, Response::HTTP_CREATED, [
            'Location' => '/api/v1/warehouses/'.$warehouse['id'],
        ]);
    }

    /**
     * Get a warehouse (cached).
     */
    #[Route('/{id}', name: 'api_warehouses_get', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    #[OA\Response(response: 200, description: 'The warehouse.', content: new OA\JsonContent(ref: new Model(type: WarehouseView::class)))]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    public function get(#[CurrentUser] MerchantUser $user, string $id): JsonResponse
    {
        return new JsonResponse($this->warehouses->get($user->id, $id));
    }

    /**
     * Update warehouse name and city (the code is immutable).
     */
    #[Route('/{id}', name: 'api_warehouses_update', requirements: ['id' => Requirement::UUID], methods: ['PUT'])]
    #[Idempotent]
    #[OA\Parameter(ref: '#/components/parameters/IdempotencyKey')]
    #[OA\Response(response: 200, description: 'The updated warehouse.', content: new OA\JsonContent(ref: new Model(type: WarehouseView::class)))]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    #[OA\Response(response: 422, ref: '#/components/responses/ValidationFailed')]
    public function update(#[CurrentUser] MerchantUser $user, string $id, #[MapRequestPayload] UpdateWarehouseRequest $dto): JsonResponse
    {
        return new JsonResponse($this->warehouses->update($user->id, $id, $dto));
    }

    /**
     * Delete a warehouse together with its stock rows.
     */
    #[Route('/{id}', name: 'api_warehouses_delete', requirements: ['id' => Requirement::UUID], methods: ['DELETE'])]
    #[OA\Response(response: 204, description: 'Deleted.')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    public function delete(#[CurrentUser] MerchantUser $user, string $id): Response
    {
        $this->warehouses->delete($user->id, $id);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
