<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\AddWishlistItem;
use App\Identity\Application\Command\RemoveWishlistItem;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

final class WishlistCommandHandler
{
    public function __construct(private Connection $connection)
    {
    }

    #[AsMessageHandler(bus: 'command.bus')]
    public function handleAddWishlistItem(AddWishlistItem $command): void
    {
        $this->connection->executeStatement(
            'INSERT INTO storefront_wishlist_item (id, customer_id, product_slug)
             VALUES (:id, :customer, :slug)
             ON CONFLICT (customer_id, product_slug) DO NOTHING',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'customer' => $command->customerId,
                'slug' => $command->productSlug,
            ],
        );
    }

    #[AsMessageHandler(bus: 'command.bus')]
    public function handleRemoveWishlistItem(RemoveWishlistItem $command): void
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
