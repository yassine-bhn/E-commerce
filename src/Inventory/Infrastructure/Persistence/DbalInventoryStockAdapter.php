<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence;

use App\Shared\Application\Port\InventoryStockPort;
use Doctrine\DBAL\Connection;

/**
 * spec-run-15 WP4 (R17) — the Inventory side of the Catalog anti-corruption port.
 *
 * `setAvailable()` stores the entered quantity as the available one: the upsert keeps an existing
 * `reserved` and moves `on_hand` to `available + reserved`, then the synchronous cache follows in
 * the same transaction. The view row must already exist (creation inserts it first); a replay never
 * inserts content, and neither does this adapter.
 */
final class DbalInventoryStockAdapter implements InventoryStockPort
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function setAvailable(string $productSlug, int $available): void
    {
        if ($available < 0) {
            throw new \InvalidArgumentException(sprintf('Available stock cannot be negative for product: %s', $productSlug));
        }

        $this->connection->transactional(function () use ($productSlug, $available): void {
            $this->connection->executeStatement(
                'INSERT INTO inventory_stock (product_slug, on_hand, reserved)
                 VALUES (:slug, :available, 0)
                 ON CONFLICT (product_slug) DO UPDATE
                 SET on_hand = EXCLUDED.on_hand + inventory_stock.reserved, updated_at = now()',
                ['slug' => $productSlug, 'available' => $available],
            );

            if (false === $this->connection->fetchOne(
                'SELECT 1 FROM storefront_product_view WHERE slug = :slug',
                ['slug' => $productSlug],
            )) {
                throw new \RuntimeException(sprintf('Cannot cache stock for missing product view: %s', $productSlug));
            }

            // An admin set is the explicit "this product is tracked" declaration, so the addressed
            // row receives the cache even when it previously carried NULL. Reserved products keep
            // their units: the subquery is the balance, never the old cache.
            $this->connection->executeStatement(
                'UPDATE storefront_product_view
                 SET stock_quantity = (SELECT on_hand - reserved FROM inventory_stock WHERE product_slug = :slug)
                 WHERE slug = :slug',
                ['slug' => $productSlug],
            );
        });
    }

    public function remove(string $productSlug): void
    {
        $this->connection->transactional(function () use ($productSlug): void {
            $this->connection->executeStatement(
                'DELETE FROM inventory_stock WHERE product_slug = :slug',
                ['slug' => $productSlug],
            );
        });
    }
}
