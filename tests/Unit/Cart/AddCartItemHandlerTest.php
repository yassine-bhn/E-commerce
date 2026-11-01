<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart;

use App\Cart\Application\Command\AddCartItem;
use App\Cart\Application\CommandHandler\AddCartItemHandler;
use App\Cart\Application\Service\CartLinePricer;
use App\Cart\Application\Service\CartLoader;
use App\Shared\Application\Dto\StorefrontProductSnapshot;
use App\Shared\Application\Service\StorefrontQuoteService;
use App\Tests\Unit\Cart\Support\FakeStorefrontCatalog;
use App\Tests\Unit\Cart\Support\FakeStorefrontPricing;
use App\Tests\Unit\Cart\Support\InMemoryCartRepository;
use PHPUnit\Framework\TestCase;

final class AddCartItemHandlerTest extends TestCase
{
    public function testAddsAPricedLineToANewCartAndSavesIt(): void
    {
        $catalog = new FakeStorefrontCatalog();
        $catalog->product = new StorefrontProductSnapshot(
            id: 'p-1',
            slug: 'fauteuil',
            name: 'Fauteuil',
            priceMinor: 2500,
            stock: 'in',
            variants: [['label' => 'headrest', 'price' => 3100]],
        );
        $pricer = new CartLinePricer(new StorefrontQuoteService($catalog, new FakeStorefrontPricing()));
        $repository = new InMemoryCartRepository();
        $handler = new AddCartItemHandler($repository, new CartLoader($repository), $catalog, $pricer);

        $handler(new AddCartItem('customer-1', 'fauteuil', null, 2));

        self::assertArrayHasKey('customer-1', $repository->carts, 'the cart is persisted');
        $line = $repository->carts['customer-1']->lines()[0];
        self::assertSame('fauteuil', $line->productSlug());
        self::assertSame(2, $line->quantity());
        self::assertSame(2500, $line->unitPriceMinor(), 'the unit price comes from the quote line');
        self::assertSame('TND', $line->currency());
    }

    public function testRefusesAnUnknownProductWithoutCreatingACart(): void
    {
        $catalog = new FakeStorefrontCatalog();
        $pricer = new CartLinePricer(new StorefrontQuoteService($catalog, new FakeStorefrontPricing()));
        $repository = new InMemoryCartRepository();
        $handler = new AddCartItemHandler($repository, new CartLoader($repository), $catalog, $pricer);

        try {
            $handler(new AddCartItem('customer-1', 'ghost', null, 1));
            self::fail('an unknown product must be refused');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $repository->carts, 'no cart is created or saved for an unknown product');
        }
    }
}
