<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Persistence;

use App\Identity\Application\Port\WishlistStore;
use Doctrine\DBAL\Connection;

/**
 * DIP-003 — the wishlist adapter; a repeat add stays a no-op (ON CONFLICT DO NOTHING).
 */
final class DbalWishlistStore implements WishlistStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function add(string $id, string $customerId, string $slug): void
    {
        $this->connection->executeStatement(
            'INSERT INTO storefront_wishlist_item (id, customer_id, product_slug)
             VALUES (:id, :customer, :slug)
             ON CONFLICT (customer_id, product_slug) DO NOTHING',
            [
                'id' => $id,
                'customer' => $customerId,
                'slug' => $slug,
            ],
        );
    }

    public function remove(string $customerId, string $slug): void
    {
        $this->connection->executeStatement(
            'DELETE FROM storefront_wishlist_item WHERE customer_id = :customer AND product_slug = :slug',
            [
                'customer' => $customerId,
                'slug' => $slug,
            ],
        );
    }
}
