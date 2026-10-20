<?php

declare(strict_types=1);

namespace App\Cart\Infrastructure\ReadModel;

use App\Cart\Application\Query\GetStorefrontCart;
use App\Catalog\Infrastructure\ReadModel\StorefrontProductView;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class StorefrontCartFinder
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array{id: ?string, items: list<array<string, mixed>>, itemCount: int}
     */
    #[AsMessageHandler(bus: 'query.bus')]
    public function handleGetStorefrontCart(GetStorefrontCart $query): array
    {
        $cartRow = $this->connection->fetchAssociative(
            'SELECT id FROM storefront_cart WHERE customer_id = :customer',
            ['customer' => $query->customerId],
        );
        if (false === $cartRow) {
            return [
                'id' => null,
                'items' => [],
                'itemCount' => 0,
            ];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT i.id, i.product_slug, i.variant_label, i.quantity, i.unit_price_minor, i.currency, p.*
             FROM storefront_cart_item i
             LEFT JOIN storefront_product_view p ON p.slug = i.product_slug
             WHERE i.cart_id = :cart
             ORDER BY i.created_at ASC',
            ['cart' => $cartRow['id']],
        );

        $items = [];
        $itemCount = 0;
        foreach ($rows as $row) {
            $qty = (int) $row['quantity'];
            $itemCount += $qty;
            $variant = isset($row['variant_label']) && is_string($row['variant_label']) && '' !== $row['variant_label']
                ? $row['variant_label']
                : null;
            $unitPrice = (int) $row['unit_price_minor'];
            $item = [
                'id' => (string) $row['id'],
                'slug' => (string) $row['product_slug'],
                'variant' => $variant,
                'quantity' => $qty,
                'unitPrice' => $unitPrice,
                'lineTotal' => $unitPrice * $qty,
                'currency' => (string) $row['currency'],
            ];
            if (isset($row['name']) && is_string($row['name'])) {
                $item['product'] = StorefrontProductView::fromRow($row)->toApi();
            }
            $items[] = $item;
        }

        return [
            'id' => (string) $cartRow['id'],
            'items' => $items,
            'itemCount' => $itemCount,
        ];
    }
}
