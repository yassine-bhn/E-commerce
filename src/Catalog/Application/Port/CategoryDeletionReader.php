<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/**
 * DIP-003 — spec R4: only an empty category is deletable; the children counted are the tree
 * nodes plus legacy parent rows, never the products.
 */
interface CategoryDeletionReader
{
    /** @return array{children: int, products: int} */
    public function counts(string $slug): array;
}
