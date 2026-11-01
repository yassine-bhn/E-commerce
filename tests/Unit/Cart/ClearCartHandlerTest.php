<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart;

use App\Cart\Application\Command\ClearCart;
use App\Cart\Application\CommandHandler\ClearCartHandler;
use App\Cart\Domain\Model\Cart;
use App\Cart\Domain\Model\CartLineId;
use App\Tests\Unit\Cart\Support\InMemoryCartRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ClearCartHandlerTest extends TestCase
{
    public function testClearsAnExistingCartAndSavesIt(): void
    {
        $repository = new InMemoryCartRepository();
        $cart = Cart::create('customer-1');
        $cart->addItem(new CartLineId(Uuid::v7()->toRfc4122()), 'fauteuil', null, 2, 2500, 'TND');
        $repository->save($cart);
        $handler = new ClearCartHandler($repository);

        $handler(new ClearCart('customer-1'));

        self::assertSame([], $repository->carts['customer-1']->lines(), 'the cart is emptied');
    }

    public function testReturnsSilentlyWhenTheCartDoesNotExist(): void
    {
        $repository = new InMemoryCartRepository();
        $handler = new ClearCartHandler($repository);

        $handler(new ClearCart('ghost-customer'));

        self::assertArrayNotHasKey('ghost-customer', $repository->carts, 'no cart is created by a clear');
    }
}
