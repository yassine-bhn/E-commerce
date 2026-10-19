<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\ReadModel;

use App\Catalog\Infrastructure\ReadModel\StorefrontProductView;
use App\Identity\Application\Query\ListWishlistItems;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class WishlistFinder
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<array<string, mixed>> */
    #[AsMessageHandler(bus: 'query.bus')]
    public function handleListWishlistItems(ListWishlistItems $query): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.*
             FROM storefront_wishlist_item w
             INNER JOIN storefront_product_view p ON p.slug = w.product_slug
             WHERE w.customer_id = :customer
             ORDER BY w.created_at DESC',
            ['customer' => $query->customerId],
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = StorefrontProductView::fromRow($row)->toApi();
        }

        return $items;
    }
}
