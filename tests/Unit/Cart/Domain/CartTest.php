<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart\Domain;

use App\Cart\Domain\Event\CartCreated;
use App\Cart\Domain\Model\Cart;
use App\Cart\Domain\Model\CartLineId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CartTest extends TestCase
{
    public function testCreateCartReturnsIdAndRecordsCartCreatedEvent(): void
    {
        $cart = Cart::create();

        self::assertNotSame('', $cart->id());

        $events = $cart->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(CartCreated::class, $events[0]);
        self::assertSame($cart->id(), $events[0]->cartId);
    }

    public function testAddItemOnEmptyCartCreatesLineAndItemCountEqualsQuantity(): void
    {
        $cart = Cart::create();

        $line = $cart->addItem($this->newLineId(), 'product-a', null, 3, 1000, 'EUR');

        self::assertCount(1, $cart->lines());
        self::assertSame($line, $cart->lines()[0]);
        self::assertSame('product-a', $line->productSlug());
        self::assertSame(3, $line->quantity());
        self::assertSame(3, $cart->itemCount());
    }

    public function testAddItemTwiceWithSameProductAndVariantMergesIntoExistingLine(): void
    {
        $cart = Cart::create();

        $first = $cart->addItem($this->newLineId(), 'product-a', 'red', 2, 1000, 'EUR');
        $second = $cart->addItem($this->newLineId(), 'product-a', 'red', 3, 1000, 'EUR');

        self::assertSame($first, $second);
        self::assertCount(1, $cart->lines());
        self::assertSame(5, $cart->itemCount());
        self::assertSame(5, $cart->lines()[0]->quantity());
    }

    public function testAddItemWithSameProductButDifferentVariantCreatesSeparateLine(): void
    {
        $cart = Cart::create();

        $cart->addItem($this->newLineId(), 'product-a', 'red', 2, 1000, 'EUR');
        $cart->addItem($this->newLineId(), 'product-a', 'blue', 1, 1000, 'EUR');

        self::assertCount(2, $cart->lines());
        self::assertSame(3, $cart->itemCount());
    }

    public function testUpdateItemQuantityChangesQuantityOfExistingLine(): void
    {
        $cart = Cart::create();
        $lineId = $this->newLineId();
        $cart->addItem($lineId, 'product-a', null, 2, 1000, 'EUR');

        $updated = $cart->updateItemQuantity($lineId->toString(), 7);

        self::assertSame(7, $updated->quantity());
        self::assertSame(7, $cart->findLine($lineId->toString())?->quantity());
        self::assertSame(7, $cart->itemCount());
    }

    public function testUpdateItemVariantSetsVariantAndNewUnitPrice(): void
    {
        $cart = Cart::create();
        $lineId = $this->newLineId();
        $cart->addItem($lineId, 'product-a', 'red', 2, 1000, 'EUR');

        $updated = $cart->updateItemVariant($lineId->toString(), 'blue', 1500);

        self::assertSame('blue', $updated->variant());
        self::assertSame(1500, $updated->unitPriceMinor());
        $found = $cart->findLine($lineId->toString());
        self::assertNotNull($found);
        self::assertSame('blue', $found->variant());
        self::assertSame(1500, $found->unitPriceMinor());
    }

    public function testRemoveItemDropsTheLine(): void
    {
        $cart = Cart::create();
        $lineId = $this->newLineId();
        $cart->addItem($lineId, 'product-a', null, 2, 1000, 'EUR');

        $cart->removeItem($lineId->toString());

        self::assertCount(0, $cart->lines());
        self::assertNull($cart->findLine($lineId->toString()));
        self::assertSame(0, $cart->itemCount());
    }

    public function testClearEmptiesLinesAndItemCount(): void
    {
        $cart = Cart::create();
        $cart->addItem($this->newLineId(), 'product-a', null, 2, 1000, 'EUR');
        $cart->addItem($this->newLineId(), 'product-b', null, 1, 500, 'EUR');

        $cart->clear();

        self::assertCount(0, $cart->lines());
        self::assertSame(0, $cart->itemCount());
    }

    public function testFindLineReturnsNullForUnknownId(): void
    {
        $cart = Cart::create();
        $cart->addItem($this->newLineId(), 'product-a', null, 2, 1000, 'EUR');

        self::assertNull($cart->findLine($this->newLineId()->toString()));
    }

    public function testUpdateItemQuantityWithUnknownLineIdThrows(): void
    {
        $cart = Cart::create();

        self::expectException(\InvalidArgumentException::class);

        $cart->updateItemQuantity($this->newLineId()->toString(), 3);
    }

    private function newLineId(): CartLineId
    {
        return new CartLineId(Uuid::v7()->toRfc4122());
    }
}
