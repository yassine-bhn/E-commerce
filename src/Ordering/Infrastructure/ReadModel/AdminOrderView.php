<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\ReadModel;

use App\Ordering\Domain\Model\OrderStatus;

/**
 * One row of the dashboard order list. Money is integer minor units with its currency (ECO-001),
 * the values snapshotted when the order was placed (ECO-006).
 *
 * `version` is the optimistic-lock token the status transition endpoint requires, and
 * `allowedTransitions` lists the legal next statuses so the UI offers exactly those.
 */
final readonly class AdminOrderView implements \JsonSerializable
{
    /**
     * @param list<AdminCustomerOrderLineView> $lines
     */
    public function __construct(
        public string $orderId,
        public string $orderNumber,
        public OrderStatus $status,
        public int $version,
        public string $source,
        public string $customerName,
        public ?string $customerEmail,
        public ?string $customerPhone,
        public ?string $shippingAddress,
        public ?string $adminNotes,
        public string $placedAt,
        public int $subtotalMinor,
        public int $discountMinor,
        public int $deliveryMinor,
        public int $totalMinor,
        public string $currency,
        public string $deliveryMethod,
        public string $paymentMethod,
        public ?string $promoCode,
        public array $lines,
    ) {
    }

    /**
     * @param array<string, mixed>             $row
     * @param list<AdminCustomerOrderLineView> $lines
     */
    public static function fromRow(array $row, array $lines): self
    {
        return new self(
            (string) $row['id'],
            (string) $row['order_number'],
            OrderStatus::from((string) $row['status']),
            (int) $row['version'],
            (string) $row['order_source'],
            (string) ($row['contact_name'] ?? ''),
            null === $row['contact_email'] ? null : (string) $row['contact_email'],
            null === $row['contact_phone'] ? null : (string) $row['contact_phone'],
            null === $row['shipping_address'] ? null : (string) $row['shipping_address'],
            null === $row['admin_notes'] ? null : (string) $row['admin_notes'],
            (string) $row['placed_at'],
            (int) $row['subtotal_minor'],
            (int) $row['discount_minor'],
            (int) $row['delivery_minor'],
            (int) $row['total_minor'],
            (string) $row['currency'],
            (string) $row['delivery_method'],
            (string) $row['payment_method'],
            null === $row['promo_code'] ? null : (string) $row['promo_code'],
            $lines,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'orderId' => $this->orderId,
            'orderNumber' => $this->orderNumber,
            // Raw persisted value: this is what the transition endpoint expects as `targetStatus`.
            'status' => $this->status->value,
            'statusSlug' => AdminOrderStatus::slug($this->status),
            'statusLabel' => AdminOrderStatus::label($this->status),
            'version' => $this->version,
            'allowedTransitions' => AdminOrderStatus::transitionsFrom($this->status),
            'source' => $this->source,
            'customer' => [
                'name' => $this->customerName,
                'email' => $this->customerEmail,
                'phone' => $this->customerPhone,
            ],
            'shippingAddress' => $this->shippingAddress,
            'adminNotes' => $this->adminNotes,
            'date' => substr($this->placedAt, 0, 10),
            'placedAt' => $this->placedAt,
            'subtotal' => $this->subtotalMinor,
            'discount' => $this->discountMinor,
            'delivery' => $this->deliveryMinor,
            'total' => $this->totalMinor,
            'currency' => $this->currency,
            'method' => $this->deliveryMethod,
            'payment' => $this->paymentMethod,
            'promoCode' => $this->promoCode,
            'itemCount' => array_sum(array_map(static fn (AdminCustomerOrderLineView $line): int => $line->quantity, $this->lines)),
            'lines' => array_map(static fn (AdminCustomerOrderLineView $line): array => $line->toArray(), $this->lines),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
