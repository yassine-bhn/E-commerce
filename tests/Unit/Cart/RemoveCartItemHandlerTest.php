<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart;

use App\Cart\Application\Command\RemoveCartItem;
use App\Cart\Application\CommandHandler\RemoveCartItemHandler;
use App\Cart\Application\Service\CartLoader;
use App\Cart\Domain\Model\Cart;
use App\Cart\Domain\Model\CartLineId;
use App\Tests\Unit\Cart\Support\InMemoryCartRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class RemoveCartItemHandlerTest extends TestCase
{
    public function testRemovesTheLineAndSavesTheCart(): void
    {
        [$repository, $handler, $itemId] = $this->handlerWithSeededCart();

        $handler(new RemoveCartItem('customer-1', $itemId));

        self::assertSame([], $repository->carts['customer-1']->lines(), 'the line is gone from the persisted cart');
    }

    public function testRefusesAnUnknownItemAndKeepsTheCartIntact(): void
    {
        [$repository, $handler] = $this->handlerWithSeededCart();

        try {
            $handler(new RemoveCartItem('customer-1', 'no-such-line'));
            self::fail('an unknown cart item must be refused');
        } catch (\InvalidArgumentException) {
            self::assertCount(1, $repository->carts['customer-1']->lines(), 'the existing line survives a refused removal');
        }
    }

    /**
     * @return array{0: InMemoryCartRepository, 1: RemoveCartItemHandler, 2: string}
     */
    private function handlerWithSeededCart(): array
    {
        $repository = new InMemoryCartRepository();
        $cart = Cart::create('customer-1');
        $cart->addItem(new CartLineId(Uuid::v7()->toRfc4122()), 'fauteuil', null, 1, 2500, 'TND');
        $repository->save($cart);
        $itemId = $cart->lines()[0]->id()->toString();
        $handler = new RemoveCartItemHandler($repository, new CartLoader($repository));

        return [$repository, $handler, $itemId];
    }
}
