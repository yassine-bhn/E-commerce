<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart;

use App\Shared\Application\Dto\StorefrontProductSnapshot;
use App\Shared\Application\Port\StorefrontCatalogPort;
use App\Shared\Application\Port\StorefrontPricingPort;
use App\Shared\Application\Service\StorefrontQuoteService;
use App\Shared\Domain\Storefront\DeliveryPolicy;
use PHPUnit\Framework\TestCase;

final class CartQuoteCalculatorTest extends TestCase
{
    public function testQuoteWithPromoAndDelivery(): void
    {
        $catalog = new class implements StorefrontCatalogPort {
            public function getBySlug(string $slug): StorefrontProductSnapshot
            {
                return new StorefrontProductSnapshot('id', 'lavabo', 'Lavabo', 680, 'in', []);
            }
        };

        $pricing = new class implements StorefrontPricingPort {
            public function deliveryPolicy(): DeliveryPolicy
            {
                return new DeliveryPolicy(15, 1000);
            }

            public function promoBasisPoints(?string $code): ?int
            {
                return 'ZIDEN10' === $code ? 1000 : null;
            }
        };

        $result = (new StorefrontQuoteService($catalog, $pricing))->quote(
            [['slug' => 'lavabo', 'quantity' => 1]],
            'ZIDEN10',
            'home',
        );

        self::assertSame(680, $result['subtotal']->minorUnits());
        self::assertSame(68, $result['discount']->minorUnits());
        self::assertSame(15, $result['delivery']->minorUnits());
        self::assertSame(627, $result['total']->minorUnits());
        self::assertSame(1, $result['itemCount']);
    }
}
