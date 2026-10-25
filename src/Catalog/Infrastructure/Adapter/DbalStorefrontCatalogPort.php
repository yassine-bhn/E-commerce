<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Adapter;

use App\Catalog\Infrastructure\ReadModel\StorefrontProductRepository;
use App\Shared\Application\Dto\StorefrontProductSnapshot;
use App\Shared\Application\Port\StorefrontCatalogPort;

final class DbalStorefrontCatalogPort implements StorefrontCatalogPort
{
    public function __construct(private StorefrontProductRepository $products)
    {
    }

    public function getBySlug(string $slug): ?StorefrontProductSnapshot
    {
        $product = $this->products->bySlug($slug);
        if (null === $product) {
            return null;
        }

        return new StorefrontProductSnapshot(
            $product->id,
            $product->slug,
            $product->name,
            $product->priceMinor,
            $product->stock,
            $product->variants,
            $product->stockQuantity,
        );
    }
}
