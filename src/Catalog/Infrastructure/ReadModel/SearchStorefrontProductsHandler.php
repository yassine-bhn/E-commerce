<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

use App\Catalog\Application\Query\SearchStorefrontProducts;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class SearchStorefrontProductsHandler
{
    public function __construct(private StorefrontProductRepository $products)
    {
    }

    /** @return list<StorefrontProductView> */
    public function __invoke(SearchStorefrontProducts $query): array
    {
        return $this->products->search($query->query, $query->limit);
    }
}
