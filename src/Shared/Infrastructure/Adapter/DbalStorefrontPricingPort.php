<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Adapter;

use App\Shared\Application\Port\StorefrontPricingPort;
use App\Shared\Domain\Storefront\DeliveryPolicy;
use App\Shared\Infrastructure\ReadModel\StorefrontReferenceRepository;

final class DbalStorefrontPricingPort implements StorefrontPricingPort
{
    public function __construct(private StorefrontReferenceRepository $reference)
    {
    }

    public function deliveryPolicy(): DeliveryPolicy
    {
        return $this->reference->deliveryPolicy();
    }

    public function promoBasisPoints(?string $code): ?int
    {
        return $this->reference->promoBasisPoints($code);
    }
}
