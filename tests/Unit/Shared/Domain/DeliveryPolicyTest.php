<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\Storefront\DeliveryPolicy;
use PHPUnit\Framework\TestCase;

final class DeliveryPolicyTest extends TestCase
{
    private DeliveryPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new DeliveryPolicy(15000, 1000000);
    }

    public function testHomeDeliveryFeeBelowFreeThreshold(): void
    {
        self::assertSame(15000, $this->policy->deliveryFeeMinor(500000, 'home'));
    }

    public function testFreeDeliveryAtThreshold(): void
    {
        self::assertSame(0, $this->policy->deliveryFeeMinor(1000000, 'home'));
    }

    public function testPickupIsAlwaysFree(): void
    {
        self::assertSame(0, $this->policy->deliveryFeeMinor(100000, 'pickup'));
    }
}
