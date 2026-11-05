<?php

declare(strict_types=1);

namespace App\Cart\Application\CommandHandler;

use App\Cart\Application\Command\UpdateCartItem;
use App\Cart\Application\Service\CartLinePricer;
use App\Cart\Application\Service\CartLoader;
use App\Cart\Domain\Repository\CartRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class UpdateCartItemHandler
{
    public function __construct(
        private CartRepository $carts,
        private CartLoader $loader,
        private CartLinePricer $pricer,
    ) {
    }

    #[AsMessageHandler(bus: 'command.bus')]
    public function __invoke(UpdateCartItem $command): void
    {
        $cart = $this->loader->require($command->customerId);
        $line = $cart->findLine($command->itemId);
        if (null === $line) {
            throw new \InvalidArgumentException('Cart item not found');
        }

        if (null !== $command->variant) {
            $pricing = $this->pricer->price($line->productSlug(), $command->variant, 1);
            $cart->updateItemVariant($command->itemId, $command->variant, $pricing['unitPriceMinor']);
        }

        if (null !== $command->quantity) {
            $cart->updateItemQuantity($command->itemId, max(1, $command->quantity));
        }

        $this->carts->save($cart);
    }
}
