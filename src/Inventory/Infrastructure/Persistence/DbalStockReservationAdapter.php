<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence;

use App\Shared\Application\Port\StockReservationPort;
use App\Shared\Domain\Inventory\InsufficientStockException;
use Doctrine\DBAL\Connection;

/**
 * spec-run-15 WP4 (R15/R16/R17) — atomic reserve/release against the Inventory-owned balance.
 *
 * `inventory_reservation` remains the per-order log; `inventory_stock` is the balance. The
 * reservation row is inserted first so a retry of the same order is idempotent, then one conditional
 * UPDATE moves `reserved` only when `on_hand - reserved >= quantity`. That statement is the whole
 * concurrency guard (ECO-004): there is no read-then-write, and `stock_quantity` is never the input
 * of a balance computation.
 *
 * Zero affected rows are classified rather than guessed: an existing balance row means the quantity
 * is insufficient; a missing balance row for a tracked product fails closed (R15); an untracked
 * product (null `stock_quantity`) keeps its legacy reservation-free behaviour; a missing view row is
 * an error too. The caller owns the outer transaction, so this adapter never starts one that could
 * hide partial work.
 */
final class DbalStockReservationAdapter implements StockReservationPort
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function reserve(string $orderId, array $lines): void
    {
        // ECO-004 — the reservation is keyed by (order_id, product_slug), so two lines of the
        // same product (two variants) must be reserved as one summed quantity.
        /** @var array<string, int> $quantities */
        $quantities = [];
        foreach ($lines as $line) {
            $slug = $line['slug'];
            $quantity = $line['quantity'];
            if ($quantity < 1) {
                throw new InsufficientStockException($slug);
            }

            $quantities[$slug] = ($quantities[$slug] ?? 0) + $quantity;
        }

        foreach ($quantities as $slug => $quantity) {
            $inserted = $this->connection->executeStatement(
                'INSERT INTO inventory_reservation (order_id, product_slug, quantity)
                 VALUES (:order_id, :slug, :quantity)
                 ON CONFLICT (order_id, product_slug) DO NOTHING',
                ['order_id' => $orderId, 'slug' => $slug, 'quantity' => $quantity],
            );
            if (0 === $inserted) {
                // The order already holds this reservation: a retry must not reserve twice.
                continue;
            }

            $updated = $this->connection->executeStatement(
                'UPDATE inventory_stock
                 SET reserved = reserved + :quantity, updated_at = now()
                 WHERE product_slug = :slug AND on_hand - reserved >= :quantity',
                ['quantity' => $quantity, 'slug' => $slug],
            );
            if (1 === $updated) {
                $this->refreshCache($slug);

                continue;
            }

            if (false !== $this->connection->fetchOne(
                'SELECT 1 FROM inventory_stock WHERE product_slug = :slug',
                ['slug' => $slug],
            )) {
                throw new InsufficientStockException($slug);
            }

            $tracked = $this->connection->fetchOne(
                'SELECT stock_quantity FROM storefront_product_view WHERE slug = :slug',
                ['slug' => $slug],
            );
            if (false === $tracked) {
                // No view row and no balance: nothing can legitimise this reservation.
                throw new InsufficientStockException($slug);
            }

            if (null === $tracked) {
                // Legacy product without a tracked quantity: the stock label stays authoritative,
                // so the provisional reservation row is dropped and checkout continues.
                $this->connection->executeStatement(
                    'DELETE FROM inventory_reservation WHERE order_id = :order_id AND product_slug = :slug',
                    ['order_id' => $orderId, 'slug' => $slug],
                );

                continue;
            }

            // Tracked in the view but missing its authoritative row: fail closed (R15).
            throw new InsufficientStockException($slug);
        }
    }

    public function release(string $orderId): void
    {
        $rows = $this->connection->fetchAllAssociative(
            "UPDATE inventory_reservation
             SET status = 'released', released_at = NOW()
             WHERE order_id = :order_id AND status = 'reserved'
             RETURNING product_slug, quantity",
            ['order_id' => $orderId],
        );

        foreach ($rows as $row) {
            $quantity = (int) $row['quantity'];
            $slug = (string) $row['product_slug'];

            // Untracked products have no balance row; their release changes no balance.
            $decremented = $this->connection->executeStatement(
                'UPDATE inventory_stock
                 SET reserved = reserved - :quantity, updated_at = now()
                 WHERE product_slug = :slug AND reserved >= :quantity',
                ['quantity' => $quantity, 'slug' => $slug],
            );
            if (1 === $decremented) {
                $this->refreshCache($slug);
            }
        }
    }

    private function refreshCache(string $slug): void
    {
        $this->connection->executeStatement(
            'UPDATE storefront_product_view
             SET stock_quantity = (SELECT on_hand - reserved FROM inventory_stock WHERE product_slug = :slug)
             WHERE slug = :slug AND stock_quantity IS NOT NULL',
            ['slug' => $slug],
        );
    }
}
