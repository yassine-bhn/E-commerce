<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use App\Shared\Domain\Storefront\DeliveryPolicy;

interface StorefrontPricingPort
{
    public function deliveryPolicy(): DeliveryPolicy;

    public function promoBasisPoints(?string $code): ?int;
}
