<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

use App\Catalog\Application\Query\ListStorefrontProducts;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class ListStorefrontProductsHandler
{
    public function __construct(private StorefrontProductRepository $products)
    {
    }

    /** @return list<StorefrontProductView> */
    public function __invoke(ListStorefrontProducts $query): array
    {
        return $this->products->list(
            $query->categorySlug,
            $query->subcategory,
            $query->promotionsOnly,
            $query->brand,
            $query->isNew,
            $query->isBest,
            $query->limit,
            $query->sort,
        );
    }
}
