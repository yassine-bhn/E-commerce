<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service;

use App\Catalog\Domain\Model\ProductHasOrdersCannotBeDeletedException;
use Doctrine\DBAL\Connection;

final class ProductDeletionGuard
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function ensureCanDeleteProduct(string $productSlug): void
    {
        $ordersCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(DISTINCT order_id) FROM ordering_order_lines WHERE product_slug = :slug',
            ['slug' => $productSlug],
        );

        if ($ordersCount > 0) {
            throw ProductHasOrdersCannotBeDeletedException::forProduct($productSlug, $ordersCount);
        }
    }
}
