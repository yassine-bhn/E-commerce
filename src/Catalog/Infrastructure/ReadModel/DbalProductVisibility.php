<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

use App\Catalog\Application\Port\ProductVisibilityPort;
use Doctrine\DBAL\Connection;

/** The {@see ProductVisibilityPort} answer, asked of the one SQL predicate the storefront reads use. */
final class DbalProductVisibility implements ProductVisibilityPort
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function isPubliclyVisible(string $slug): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM storefront_product_view WHERE slug = :slug AND '.CatalogVisibility::PRODUCT_PUBLIC,
            ['slug' => $slug],
        );
    }
}
