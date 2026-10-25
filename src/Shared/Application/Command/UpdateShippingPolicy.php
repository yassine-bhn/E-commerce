<?php

declare(strict_types=1);

namespace App\Shared\Application\Command;

/**
 * I-16 — the delivery fee and free-delivery threshold an operator owns.
 */
final readonly class UpdateShippingPolicy
{
    public function __construct(
        public int $deliveryFeeMinor,
        public int $freeDeliveryThresholdMinor,
    ) {
    }
}
