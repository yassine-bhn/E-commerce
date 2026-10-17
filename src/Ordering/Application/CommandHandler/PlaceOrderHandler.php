<?php

declare(strict_types=1);

namespace App\Ordering\Application\CommandHandler;

use App\Ordering\Application\Command\PlaceOrder;
use App\Shared\Application\Service\StorefrontQuoteService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final class PlaceOrderHandler
{
    public function __construct(
        private Connection $connection,
        private StorefrontQuoteService $quotes,
    ) {
    }

    public function __invoke(PlaceOrder $command): void
    {
        $inserted = $this->connection->executeStatement(
            'INSERT INTO processed_commands (key) VALUES (:key) ON CONFLICT DO NOTHING',
            ['key' => $command->idempotencyKey],
        );
        if (0 === $inserted) {
            return;
        }

        $quote = $this->quotes->quote($command->lines, $command->promoCode, $command->deliveryMethod);

        $this->connection->executeStatement(
            'INSERT INTO ordering_orders (
                id, customer_id, status, order_number, subtotal_minor, discount_minor, delivery_minor, total_minor,
                currency, delivery_method, payment_method, promo_code, placed_at
            ) VALUES (
                :id, :customer_id, :status, :order_number, :subtotal_minor, :discount_minor, :delivery_minor, :total_minor,
                :currency, :delivery_method, :payment_method, :promo_code, :placed_at
            ) ON CONFLICT (id) DO NOTHING',
            [
                'id' => $command->orderId,
                'customer_id' => $command->customerId,
                'status' => 'En préparation',
                'order_number' => $command->orderNumber,
                'subtotal_minor' => $quote['subtotal']->minorUnits(),
                'discount_minor' => $quote['discount']->minorUnits(),
                'delivery_minor' => $quote['delivery']->minorUnits(),
                'total_minor' => $quote['total']->minorUnits(),
                'currency' => $quote['total']->currency()->value,
                'delivery_method' => $command->deliveryMethod,
                'payment_method' => $command->paymentMethod,
                'promo_code' => $command->promoCode,
                'placed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP'),
            ],
        );

        foreach ($quote['pricedLines'] as $line) {
            $this->connection->executeStatement(
                'INSERT INTO ordering_order_lines (id, order_id, product_slug, product_name, variant_label, unit_price_minor, currency, quantity)
                 VALUES (:id, :order_id, :slug, :name, :variant, :price, :currency, :qty)',
                [
                    'id' => Uuid::v7()->toRfc4122(),
                    'order_id' => $command->orderId,
                    'slug' => $line['slug'],
                    'name' => $line['name'],
                    'variant' => $line['variant'],
                    'price' => $line['unitPriceMinor'],
                    'currency' => $quote['total']->currency()->value,
                    'qty' => $line['quantity'],
                ],
            );
        }
    }
}
