<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

/**
 * DIP-003 — the wishlist writes; a repeat add is a no-op.
 */
interface WishlistStore
{
    public function add(string $id, string $customerId, string $slug): void;

    public function remove(string $customerId, string $slug): void;
}
