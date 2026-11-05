<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart\Support;

use App\Shared\Application\Port\StorefrontPricingPort;
use App\Shared\Domain\Storefront\DeliveryPolicy;

final class FakeStorefrontPricing implements StorefrontPricingPort
{
    public function deliveryPolicy(): DeliveryPolicy
    {
        return new DeliveryPolicy(700, 50000);
    }

    public function promoBasisPoints(?string $code): ?int
    {
        return null;
    }
}
