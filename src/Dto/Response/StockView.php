<?php

declare(strict_types=1);

namespace App\Dto\Response;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

final readonly class StockView
{
    /**
     * @param list<WarehouseStockView> $warehouses
     */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $productId,
        #[OA\Property(description: 'Sum of quantities over all warehouses.')]
        public int $total,
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: WarehouseStockView::class)))]
        public array $warehouses,
    ) {
    }
}
