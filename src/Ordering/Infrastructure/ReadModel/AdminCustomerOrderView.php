<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\ReadModel;

/**
 * One order of the admin dashboard's "orders of this customer" read (R16).
 *
 * Totals and line prices are integer minor units with their currency — never floats (ECO-001) —
 * and are the values snapshotted when the order was placed, never recomputed from the live
 * catalog (ECO-006). Serializing through `toArray()` keeps the cursor envelope's `items` in the
 * same shape as before pagination was added.
 *
 * @param list<AdminCustomerOrderLineView> $lines
 */
final readonly class AdminCustomerOrderView implements \JsonSerializable
{
    /**
     * @param list<AdminCustomerOrderLineView> $lines
     */
    public function __construct(
        public string $orderId,
        public string $orderNumber,
        public string $status,
        public string $statusLabel,
        public string $date,
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
     * @param array<string, mixed>             $order
     * @param list<AdminCustomerOrderLineView> $lines
     */
    public static function fromRow(array $order, array $lines): self
    {
        $placedAt = (string) $order['placed_at'];

        return new self(
            (string) $order['id'],
            (string) $order['order_number'],
            (string) $order['status'],
            StorefrontOrderStatus::label((string) $order['status']),
            substr($placedAt, 0, 10),
            $placedAt,
            (int) $order['subtotal_minor'],
            (int) $order['discount_minor'],
            (int) $order['delivery_minor'],
            (int) $order['total_minor'],
            (string) $order['currency'],
            (string) $order['delivery_method'],
            (string) $order['payment_method'],
            null === $order['promo_code'] ? null : (string) $order['promo_code'],
            $lines,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'orderId' => $this->orderId,
            'orderNumber' => $this->orderNumber,
            'status' => $this->status,
            'statusLabel' => $this->statusLabel,
            'date' => $this->date,
            'placedAt' => $this->placedAt,
            'subtotal' => $this->subtotalMinor,
            'discount' => $this->discountMinor,
            'delivery' => $this->deliveryMinor,
            'total' => $this->totalMinor,
            'currency' => $this->currency,
            'method' => $this->deliveryMethod,
            'payment' => $this->paymentMethod,
            'promoCode' => $this->promoCode,
            'lines' => array_map(static fn (AdminCustomerOrderLineView $line): array => $line->toArray(), $this->lines),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
