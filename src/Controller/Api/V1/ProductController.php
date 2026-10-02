<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Dto\Request\CreateProductRequest;
use App\Dto\Request\ListProductsQuery;
use App\Dto\Request\UpdateProductRequest;
use App\Dto\Response\ProductListView;
use App\Dto\Response\ProductView;
use App\Idempotency\Attribute\Idempotent;
use App\Security\MerchantUser;
use App\Service\ProductService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[AsController]
#[Route('/api/v1/products')]
#[OA\Tag(name: 'Products')]
final class ProductController
{
    public function __construct(private readonly ProductService $products)
    {
    }

    /**
     * List products (newest first, cached).
     */
    #[Route('', name: 'api_products_list', methods: ['GET'])]
    #[OA\Response(response: 200, description: 'A page of products.', content: new OA\JsonContent(ref: new Model(type: ProductListView::class)))]
    #[OA\Response(response: 422, ref: '#/components/responses/ValidationFailed')]
    public function list(
        #[CurrentUser] MerchantUser $user,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] ListProductsQuery $query = new ListProductsQuery(),
    ): JsonResponse {
        return new JsonResponse($this->products->list($user->id, $query));
    }

    /**
     * Create a product.
     */
    #[Route('', name: 'api_products_create', methods: ['POST'])]
    #[Idempotent]
    #[OA\Parameter(ref: '#/components/parameters/IdempotencyKey')]
    #[OA\Response(response: 201, description: 'Product created.', content: new OA\JsonContent(ref: new Model(type: ProductView::class)))]
    #[OA\Response(response: 409, ref: '#/components/responses/Conflict')]
    #[OA\Response(response: 422, ref: '#/components/responses/ValidationFailed')]
    public function create(#[CurrentUser] MerchantUser $user, #[MapRequestPayload] CreateProductRequest $dto): JsonResponse
    {
        $product = $this->products->create($user->id, $dto);

        return new JsonResponse($product, Response::HTTP_CREATED, [
            'Location' => '/api/v1/products/'.$product['id'],
        ]);
    }

    /**
     * Get a product with its prices (cached).
     */
    #[Route('/{id}', name: 'api_products_get', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    #[OA\Response(response: 200, description: 'The product.', content: new OA\JsonContent(ref: new Model(type: ProductView::class)))]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    public function get(#[CurrentUser] MerchantUser $user, string $id): JsonResponse
    {
        return new JsonResponse($this->products->get($user->id, $id));
    }

    /**
     * Replace product attributes.
     */
    #[Route('/{id}', name: 'api_products_update', requirements: ['id' => Requirement::UUID], methods: ['PUT'])]
    #[Idempotent]
    #[OA\Parameter(ref: '#/components/parameters/IdempotencyKey')]
    #[OA\Response(response: 200, description: 'The updated product.', content: new OA\JsonContent(ref: new Model(type: ProductView::class)))]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    #[OA\Response(response: 422, ref: '#/components/responses/ValidationFailed')]
    public function update(#[CurrentUser] MerchantUser $user, string $id, #[MapRequestPayload] UpdateProductRequest $dto): JsonResponse
    {
        return new JsonResponse($this->products->update($user->id, $id, $dto));
    }

    /**
     * Delete a product together with its prices and stock.
     */
    #[Route('/{id}', name: 'api_products_delete', requirements: ['id' => Requirement::UUID], methods: ['DELETE'])]
    #[OA\Response(response: 204, description: 'Deleted.')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    public function delete(#[CurrentUser] MerchantUser $user, string $id): Response
    {
        $this->products->delete($user->id, $id);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
