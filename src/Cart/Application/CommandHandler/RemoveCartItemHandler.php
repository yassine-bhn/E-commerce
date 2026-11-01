<?php

declare(strict_types=1);

namespace App\Cart\Application\CommandHandler;

use App\Cart\Application\Command\RemoveCartItem;
use App\Cart\Application\Service\CartLoader;
use App\Cart\Domain\Repository\CartRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class RemoveCartItemHandler
{
    public function __construct(
        private CartRepository $carts,
        private CartLoader $loader,
    ) {
    }

    #[AsMessageHandler(bus: 'command.bus')]
    public function __invoke(RemoveCartItem $command): void
    {
        $cart = $this->loader->require($command->customerId);
        if (null === $cart->findLine($command->itemId)) {
            throw new \InvalidArgumentException('Cart item not found');
        }
        $cart->removeItem($command->itemId);
        $this->carts->save($cart);
    }
}
