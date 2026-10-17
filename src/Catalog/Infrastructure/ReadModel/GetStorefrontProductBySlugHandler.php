<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

use App\Catalog\Application\Query\GetStorefrontProductBySlug;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class GetStorefrontProductBySlugHandler
{
    public function __construct(private StorefrontProductRepository $products)
    {
    }

    public function __invoke(GetStorefrontProductBySlug $query): ?StorefrontProductView
    {
        return $this->products->bySlug($query->slug);
    }
}
