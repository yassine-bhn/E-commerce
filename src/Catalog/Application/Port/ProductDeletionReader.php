<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/**
 * DIP-003 — a product with order history is never deletable (order lines keep their snapshot).
 */
interface ProductDeletionReader
{
    public function orderCount(string $slug): int;
}
