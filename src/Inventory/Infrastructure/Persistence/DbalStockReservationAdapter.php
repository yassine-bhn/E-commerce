<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence;

use App\Shared\Application\Port\StockReservationPort;
use App\Shared\Domain\Inventory\InsufficientStockException;
use Doctrine\DBAL\Connection;

final class DbalStockReservationAdapter implements StockReservationPort
{
    public function __construct(private Connection $connection)
    {
    }

    public function reserve(string $orderId, array $lines): void
    {
        // ECO-004 — the reservation is keyed by (order_id, product_slug), so two lines of the
        // same product (two variants) must be reserved as one summed quantity.
        /** @var array<string, int> $quantities */
        $quantities = [];
        foreach ($lines as $line) {
            $slug = (string) $line['slug'];
            $quantity = (int) $line['quantity'];
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
                continue;
            }

            $updated = $this->connection->executeStatement(
                'UPDATE storefront_product_view
                 SET stock_quantity = stock_quantity - :quantity
                 WHERE slug = :slug AND stock_quantity IS NOT NULL AND stock_quantity >= :quantity',
                ['quantity' => $quantity, 'slug' => $slug],
            );
            if (1 === $updated) {
                continue;
            }

            $tracked = $this->connection->fetchOne(
                'SELECT stock_quantity FROM storefront_product_view WHERE slug = :slug',
                ['slug' => $slug],
            );

            if (null === $tracked) {
                // Legacy product without a tracked quantity: the stock label stays authoritative.
                $this->connection->executeStatement(
                    'DELETE FROM inventory_reservation WHERE order_id = :order_id AND product_slug = :slug',
                    ['order_id' => $orderId, 'slug' => $slug],
                );

                continue;
            }

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
            $this->connection->executeStatement(
                'UPDATE storefront_product_view
                 SET stock_quantity = stock_quantity + :quantity
                 WHERE slug = :slug AND stock_quantity IS NOT NULL',
                ['quantity' => (int) $row['quantity'], 'slug' => (string) $row['product_slug']],
            );
        }
    }
}
