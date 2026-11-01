<?php

declare(strict_types=1);

namespace App\Cart\Application\CommandHandler;

use App\Cart\Application\Command\ClearCart;
use App\Cart\Domain\Repository\CartRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class ClearCartHandler
{
    public function __construct(private CartRepository $carts)
    {
    }

    #[AsMessageHandler(bus: 'command.bus')]
    public function __invoke(ClearCart $command): void
    {
        $cart = $this->carts->findByCustomerId($command->customerId);
        if (null === $cart) {
            return;
        }
        $cart->clear();
        $this->carts->save($cart);
    }
}
