<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\RemoveWishlistItem;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class RemoveWishlistItemHandler
{
    public function __construct(private Connection $connection)
    {
    }

    #[AsMessageHandler(bus: 'command.bus')]
    public function __invoke(RemoveWishlistItem $command): void
    {
        $this->connection->executeStatement(
            'DELETE FROM storefront_wishlist_item WHERE customer_id = :customer AND product_slug = :slug',
            [
                'customer' => $command->customerId,
                'slug' => $command->productSlug,
            ],
        );
    }
}
