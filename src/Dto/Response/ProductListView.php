<?php

declare(strict_types=1);

namespace App\Dto\Response;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

final readonly class ProductListView
{
    /**
     * @param list<ProductView> $data
     */
    public function __construct(
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: ProductView::class)))]
        public array $data,
        public PageMeta $meta,
    ) {
    }
}
