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

    public function __invoke(ListStorefrontProducts $query): StorefrontProductPage
    {
        return $this->products->browse($query->filters, $query->page);
    }
}
