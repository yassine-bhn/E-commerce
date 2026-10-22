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

    public function __invoke(SearchStorefrontProducts $query): StorefrontProductPage
    {
        return $this->products->search($query->filters, $query->page);
    }
}
