<?php

declare(strict_types=1);

namespace App\Cart\Infrastructure\Persistence;

use App\Cart\Domain\Model\Cart;
use App\Cart\Domain\Model\CartLine;
use App\Cart\Domain\Model\CartLineId;
use App\Cart\Domain\Repository\CartRepository;
use Doctrine\DBAL\Connection;

final class DbalCartRepository implements CartRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function findByCustomerId(string $customerId): ?Cart
    {
        $cartRow = $this->connection->fetchAssociative(
            'SELECT id, customer_id FROM storefront_cart WHERE customer_id = :customer',
            ['customer' => $customerId],
        );
        if (false === $cartRow) {
            return null;
        }

        $lineRows = $this->connection->fetchAllAssociative(
            'SELECT id, product_slug, variant_label, quantity, unit_price_minor, currency
             FROM storefront_cart_item
             WHERE cart_id = :cart
             ORDER BY created_at ASC',
            ['cart' => $cartRow['id']],
        );

        $lines = [];
        foreach ($lineRows as $row) {
            $variant = isset($row['variant_label']) && is_string($row['variant_label']) && '' !== $row['variant_label']
                ? $row['variant_label']
                : null;
            $lines[] = CartLine::create(
                new CartLineId((string) $row['id']),
                (string) $row['product_slug'],
                $variant,
                (int) $row['quantity'],
                (int) $row['unit_price_minor'],
                (string) $row['currency'],
            );
        }

        return Cart::reconstitute((string) $cartRow['id'], (string) $cartRow['customer_id'], $lines);
    }

    public function save(Cart $cart): void
    {
        $this->connection->executeStatement(
            'INSERT INTO storefront_cart (id, customer_id, updated_at)
             VALUES (:id, :customer, CURRENT_TIMESTAMP)
             ON CONFLICT (customer_id) DO UPDATE SET updated_at = CURRENT_TIMESTAMP',
            [
                'id' => $cart->id(),
                'customer' => $cart->customerId(),
            ],
        );

        $existingIds = $this->connection->fetchFirstColumn(
            'SELECT id FROM storefront_cart_item WHERE cart_id = :cart',
            ['cart' => $cart->id()],
        );
        $persistedIds = [];
        foreach ($cart->lines() as $line) {
            $lineId = $line->id()->toString();
            $persistedIds[] = $lineId;
            $variantLabel = $line->variant() ?? '';
            $this->connection->executeStatement(
                'INSERT INTO storefront_cart_item (
                    id, cart_id, product_slug, variant_label, quantity, unit_price_minor, currency, updated_at
                 ) VALUES (
                    :id, :cart, :slug, :variant, :qty, :unit, :currency, CURRENT_TIMESTAMP
                 )
                 ON CONFLICT (id) DO UPDATE SET
                    product_slug = EXCLUDED.product_slug,
                    variant_label = EXCLUDED.variant_label,
                    quantity = EXCLUDED.quantity,
                    unit_price_minor = EXCLUDED.unit_price_minor,
                    currency = EXCLUDED.currency,
                    updated_at = CURRENT_TIMESTAMP',
                [
                    'id' => $lineId,
                    'cart' => $cart->id(),
                    'slug' => $line->productSlug(),
                    'variant' => '' === $variantLabel ? null : $variantLabel,
                    'qty' => $line->quantity(),
                    'unit' => $line->unitPriceMinor(),
                    'currency' => $line->currency(),
                ],
            );
        }

        $toDelete = array_diff($existingIds, $persistedIds);
        foreach ($toDelete as $deleteId) {
            $this->connection->executeStatement(
                'DELETE FROM storefront_cart_item WHERE id = :id AND cart_id = :cart',
                ['id' => $deleteId, 'cart' => $cart->id()],
            );
        }
    }
}
