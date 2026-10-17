<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Event\ProductPriceChanged;
use App\Catalog\Domain\Event\ProductRegistered;
use App\Catalog\Domain\Model\Product;
use App\Catalog\Domain\Model\ProductId;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use PHPUnit\Framework\TestCase;

final class ProductTest extends TestCase
{
    public function testRegisterRecordsEvent(): void
    {
        $product = Product::create(
            new ProductId('11111111-1111-1111-1111-111111111111'),
            'Widget',
            Money::ofMinor(1999, Currency::EUR),
        );

        $events = $product->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(ProductRegistered::class, $events[0]);
    }

    public function testChangePriceRecordsEvent(): void
    {
        $product = Product::create(
            new ProductId('11111111-1111-1111-1111-111111111111'),
            'Widget',
            Money::ofMinor(1000, Currency::EUR),
        );
        $product->releaseEvents();

        $product->changePrice(Money::ofMinor(1500, Currency::EUR));
        $events = $product->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(ProductPriceChanged::class, $events[0]);
    }
}
