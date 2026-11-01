<?php

declare(strict_types=1);

namespace App\Cart\Application\CommandHandler;

use App\Cart\Application\Command\AddCartItem;
use App\Cart\Application\Service\CartLinePricer;
use App\Cart\Application\Service\CartLoader;
use App\Cart\Domain\Model\CartLineId;
use App\Cart\Domain\Repository\CartRepository;
use App\Shared\Application\Port\StorefrontCatalogPort;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

final class AddCartItemHandler
{
    public function __construct(
        private CartRepository $carts,
        private CartLoader $loader,
        private StorefrontCatalogPort $catalog,
        private CartLinePricer $pricer,
    ) {
    }

    #[AsMessageHandler(bus: 'command.bus')]
    public function __invoke(AddCartItem $command): void
    {
        if (null === $this->catalog->getBySlug($command->productSlug)) {
            throw new \InvalidArgumentException('Product not found');
        }

        $pricing = $this->pricer->price($command->productSlug, $command->variant, 1);
        $cart = $this->loader->loadOrCreate($command->customerId);
        $cart->addItem(
            new CartLineId(Uuid::v7()->toRfc4122()),
            $command->productSlug,
            $command->variant,
            max(1, $command->quantity),
            $pricing['unitPriceMinor'],
            $pricing['currency'],
        );
        $this->carts->save($cart);
    }
}
