<?php

declare(strict_types=1);

namespace App\Ordering\Application\CommandHandler;

use App\Ordering\Application\Command\CreateAdminProxyOrder;
use App\Ordering\Domain\Model\OrderStatus;
use App\Shared\Application\Port\PaymentInitiationPort;
use App\Shared\Application\Port\StockReservationPort;
use App\Shared\Application\Service\StorefrontQuoteService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final class CreateAdminProxyOrderHandler
{
    public function __construct(
        private Connection $connection,
        private StorefrontQuoteService $quotes,
        private StockReservationPort $stock,
        private PaymentInitiationPort $payments,
    ) {
    }

    public function __invoke(CreateAdminProxyOrder $command): void
    {
        $quote = $this->quotes->quote($command->lines, $command->promoCode, $command->deliveryMethod);

        $this->stock->reserve(
            $command->orderId,
            array_map(
                static fn (array $line): array => [
                    'slug' => (string) $line['slug'],
                    'quantity' => (int) $line['quantity'],
                ],
                $quote['pricedLines'],
            ),
        );

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP');

        $this->connection->executeStatement(
            'INSERT INTO ordering_orders (
                id, customer_id, status, version, order_number, order_source,
                customer_name, customer_phone, shipping_address, created_by_admin_id, admin_notes,
                subtotal_minor, discount_minor, delivery_minor, total_minor,
                currency, delivery_method, payment_method, promo_code, placed_at
            ) VALUES (
                :id, :customer_id, :status, :version, :order_number, :order_source,
                :customer_name, :customer_phone, :shipping_address, :created_by_admin_id, :admin_notes,
                :subtotal_minor, :discount_minor, :delivery_minor, :total_minor,
                :currency, :delivery_method, :payment_method, :promo_code, :placed_at
            ) ON CONFLICT (id) DO NOTHING',
            [
                'id' => $command->orderId,
                'customer_id' => Uuid::v7()->toRfc4122(), // Synthetic guest ID for phone/whatsapp orders
                'status' => OrderStatus::PENDING->value,
                'version' => 1,
                'order_number' => $command->orderNumber,
                'order_source' => $command->orderSource->value,
                'customer_name' => $command->customerName,
                'customer_phone' => $command->customerPhone,
                'shipping_address' => $command->shippingAddress,
                'created_by_admin_id' => $command->createdByAdminId,
                'admin_notes' => $command->adminNotes,
                'subtotal_minor' => $quote['subtotal']->minorUnits(),
                'discount_minor' => $quote['discount']->minorUnits(),
                'delivery_minor' => $quote['delivery']->minorUnits(),
                'total_minor' => $quote['total']->minorUnits(),
                'currency' => $quote['total']->currency()->value,
                'delivery_method' => $command->deliveryMethod,
                'payment_method' => $command->paymentMethod,
                'promo_code' => $command->promoCode,
                'placed_at' => $now,
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

        // Record initial audit trail entry
        $this->connection->executeStatement(
            'INSERT INTO ordering_order_audit_trail (id, order_id, from_status, to_status, from_version, to_version, changed_by, reason, created_at)
             VALUES (:id, :order_id, :from_status, :to_status, :from_version, :to_version, :changed_by, :reason, :created_at)',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'order_id' => $command->orderId,
                'from_status' => null,
                'to_status' => OrderStatus::PENDING->value,
                'from_version' => 0,
                'to_version' => 1,
                'changed_by' => $command->createdByAdminId,
                'reason' => 'Proxy order created via '.$command->orderSource->value,
                'created_at' => $now,
            ],
        );

        $this->payments->open(
            $command->orderId,
            $command->paymentMethod,
            $quote['total']->minorUnits(),
            $quote['total']->currency()->value,
            $command->createdByAdminId,
        );
    }
}
