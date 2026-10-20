<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart\Domain;

use App\Cart\Domain\Event\CartCreated;
use App\Cart\Domain\Model\Cart;
use PHPUnit\Framework\TestCase;

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
}
