<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart;

use App\Cart\Application\Command\UpdateCartItem;
use App\Cart\Application\CommandHandler\UpdateCartItemHandler;
use App\Cart\Application\Service\CartLinePricer;
use App\Cart\Application\Service\CartLoader;
use App\Cart\Domain\Model\Cart;
use App\Cart\Domain\Model\CartLineId;
use App\Shared\Application\Dto\StorefrontProductSnapshot;
use App\Shared\Application\Service\StorefrontQuoteService;
use App\Tests\Unit\Cart\Support\FakeStorefrontCatalog;
use App\Tests\Unit\Cart\Support\FakeStorefrontPricing;
use App\Tests\Unit\Cart\Support\InMemoryCartRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class UpdateCartItemHandlerTest extends TestCase
{
    public function testChangingTheVariantRepricesTheLineThroughTheQuote(): void
    {
        [$repository, $handler, $itemId] = $this->handlerWithSeededCart();

        $handler(new UpdateCartItem('customer-1', $itemId, null, 'headrest'));

        $line = $repository->carts['customer-1']->findLine($itemId);
        self::assertNotNull($line);
        self::assertSame('headrest', $line->variant());
        self::assertSame(3100, $line->unitPriceMinor(), 'the variant price replaces the base price');
    }

    public function testRefusesAnUpdateWhenTheCartDoesNotExist(): void
    {
        [$repository, $handler] = $this->handlerWithSeededCart();

        try {
            $handler(new UpdateCartItem('ghost-customer', 'no-such-line', 3, null));
            self::fail('an update on a missing cart must be refused');
        } catch (\InvalidArgumentException) {
            self::assertArrayNotHasKey('ghost-customer', $repository->carts, 'no cart is created by an update');
        }
    }

    /**
     * @return array{0: InMemoryCartRepository, 1: UpdateCartItemHandler, 2: string}
     */
    private function handlerWithSeededCart(): array
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
        $cart = Cart::create('customer-1');
        $cart->addItem(new CartLineId(Uuid::v7()->toRfc4122()), 'fauteuil', null, 1, 2500, 'TND');
        $repository->save($cart);
        $itemId = $cart->lines()[0]->id()->toString();
        $handler = new UpdateCartItemHandler($repository, new CartLoader($repository), $pricer);

        return [$repository, $handler, $itemId];
    }
}
