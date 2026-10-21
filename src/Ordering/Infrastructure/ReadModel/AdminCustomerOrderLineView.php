<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\ReadModel;

/**
 * One line item of an admin-facing order read. Money is integer minor units (ECO-001).
 *
 * @param array<string, mixed> $row
 */
final readonly class AdminCustomerOrderLineView
{
    public function __construct(
        public string $orderId,
        public string $productSlug,
        public string $productName,
        public ?string $variantLabel,
        public int $unitPriceMinor,
        public string $currency,
        public int $quantity,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['order_id'],
            (string) $row['product_slug'],
            (string) $row['product_name'],
            null === $row['variant_label'] ? null : (string) $row['variant_label'],
            (int) $row['unit_price_minor'],
            (string) $row['currency'],
            (int) $row['quantity'],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'slug' => $this->productSlug,
            'name' => $this->productName,
            'variant' => $this->variantLabel,
            'price' => $this->unitPriceMinor,
            'currency' => $this->currency,
            'qty' => $this->quantity,
        ];
    }
}
