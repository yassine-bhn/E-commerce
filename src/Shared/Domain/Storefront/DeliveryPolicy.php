<?php

declare(strict_types=1);

namespace App\Shared\Domain\Storefront;

final readonly class DeliveryPolicy
{
    public function __construct(
        public int $deliveryFeeMinor,
        public int $freeDeliveryThresholdMinor,
    ) {
    }

    public function deliveryFeeMinor(int $subtotalAfterDiscountMinor, string $method): int
    {
        if ('pickup' === $method || $subtotalAfterDiscountMinor >= $this->freeDeliveryThresholdMinor) {
            return 0;
        }

        return $this->deliveryFeeMinor;
    }
}
