<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/**
 * DIP-003 — the public review write, bound to the product's visibility by the handler.
 */
interface ProductReviewStore
{
    public function store(string $id, string $slug, string $authorName, int $rating, string $comment): void;
}
