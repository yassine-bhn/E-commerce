<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Catalog\Domain\Model\ProductNotFoundException;
use App\Identity\Application\Command\AddWishlistItem;
use App\Identity\Application\Port\WishlistStore;
use App\Shared\Application\Port\ProductExistencePort;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

final class AddWishlistItemHandler
{
    public function __construct(
        private WishlistStore $wishlist,
        private ProductExistencePort $products,
    ) {
    }

    #[AsMessageHandler(bus: 'command.bus')]
    public function __invoke(AddWishlistItem $command): void
    {
        if (!$this->products->exists($command->productSlug)) {
            throw ProductNotFoundException::forSlug($command->productSlug);
        }

        $this->wishlist->add(Uuid::v7()->toRfc4122(), $command->customerId, $command->productSlug);
    }
}
