<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence;

use App\Ordering\Application\Dto\AdminProxyOrderData;
use App\Ordering\Application\Port\AdminProxyOrderStore;
use App\Ordering\Domain\Model\OrderStatus;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * DIP-003 — the proxy-order adapter: the order INSERT, the priced lines and the first audit
 * entry, moved verbatim from the handler (the synthetic guest id arrives in the DTO).
 */
final class DbalAdminProxyOrderStore implements AdminProxyOrderStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function create(AdminProxyOrderData $data): void
    {
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
                'id' => $data->orderId,
                'customer_id' => $data->guestCustomerId,
                'status' => OrderStatus::PENDING->value,
                'version' => 1,
                'order_number' => $data->orderNumber,
                'order_source' => $data->orderSource,
                'customer_name' => $data->customerName,
                'customer_phone' => $data->customerPhone,
                'shipping_address' => $data->shippingAddress,
                'created_by_admin_id' => $data->createdByAdminId,
                'admin_notes' => $data->adminNotes,
                'subtotal_minor' => $data->subtotalMinor,
                'discount_minor' => $data->discountMinor,
                'delivery_minor' => $data->deliveryMinor,
                'total_minor' => $data->totalMinor,
                'currency' => $data->currency,
                'delivery_method' => $data->deliveryMethod,
                'payment_method' => $data->paymentMethod,
                'promo_code' => $data->promoCode,
                'placed_at' => $now,
            ],
        );

        foreach ($data->lines as $line) {
            $this->connection->executeStatement(
                'INSERT INTO ordering_order_lines (id, order_id, product_slug, product_name, variant_label, unit_price_minor, currency, quantity)
                 VALUES (:id, :order_id, :slug, :name, :variant, :price, :currency, :qty)',
                [
                    'id' => Uuid::v7()->toRfc4122(),
                    'order_id' => $data->orderId,
                    'slug' => $line['slug'],
                    'name' => $line['name'],
                    'variant' => $line['variant'],
                    'price' => $line['unitPriceMinor'],
                    'currency' => $data->currency,
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
                'order_id' => $data->orderId,
                'from_status' => null,
                'to_status' => OrderStatus::PENDING->value,
                'from_version' => 0,
                'to_version' => 1,
                'changed_by' => $data->createdByAdminId,
                'reason' => 'Proxy order created via '.$data->orderSource,
                'created_at' => $now,
            ],
        );
    }
}
