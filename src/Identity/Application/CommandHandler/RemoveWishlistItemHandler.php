<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\RemoveWishlistItem;
use App\Identity\Application\Port\WishlistStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class RemoveWishlistItemHandler
{
    public function __construct(private WishlistStore $wishlist)
    {
    }

    #[AsMessageHandler(bus: 'command.bus')]
    public function __invoke(RemoveWishlistItem $command): void
    {
        $this->wishlist->remove($command->customerId, $command->productSlug);
    }
}
