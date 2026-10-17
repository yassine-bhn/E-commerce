<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

final readonly class PlaceOrder
{
    /**
     * @param list<array{slug: string, variant?: string|null, quantity: int}> $lines
     */
    public function __construct(
        public string $orderId,
        public string $customerId,
        public string $idempotencyKey,
        public string $orderNumber,
        public array $lines,
        public string $deliveryMethod,
        public string $paymentMethod,
        public ?string $promoCode,
    ) {
    }
}
