<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\ReadModel;

use App\Ordering\Application\Query\ListOrdersForCustomer;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class CustomerOrderFinder
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<array<string, mixed>> */
    public function __invoke(ListOrdersForCustomer $query): array
    {
        $orders = $this->connection->fetchAllAssociative(
            'SELECT id, order_number, status, subtotal_minor, discount_minor, delivery_minor, total_minor, currency,
                    delivery_method, payment_method, promo_code, placed_at
             FROM ordering_orders WHERE customer_id = :customer ORDER BY placed_at DESC',
            ['customer' => $query->customerId],
        );

        return array_map(function (array $order): array {
            $lines = $this->connection->fetchAllAssociative(
                'SELECT product_slug, product_name, variant_label, unit_price_minor, quantity
                 FROM ordering_order_lines WHERE order_id = :id ORDER BY product_name ASC',
                ['id' => $order['id']],
            );

            return [
                'id' => (string) $order['order_number'],
                'orderId' => (string) $order['id'],
                'date' => substr((string) $order['placed_at'], 0, 10),
                'status' => (string) $order['status'],
                'subtotal' => (int) $order['subtotal_minor'],
                'discount' => (int) $order['discount_minor'],
                'delivery' => (int) $order['delivery_minor'],
                'total' => (int) $order['total_minor'],
                'currency' => (string) $order['currency'],
                'method' => (string) $order['delivery_method'],
                'payment' => (string) $order['payment_method'],
                'promoCode' => $order['promo_code'],
                'lines' => array_map(static fn (array $l): array => [
                    'slug' => (string) $l['product_slug'],
                    'variant' => $l['variant_label'],
                    'price' => (int) $l['unit_price_minor'],
                    'qty' => (int) $l['quantity'],
                ], $lines),
            ];
        }, $orders);
    }
}
