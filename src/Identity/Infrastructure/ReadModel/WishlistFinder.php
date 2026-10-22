<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\ReadModel;

use App\Catalog\Infrastructure\ReadModel\StorefrontProductView;
use App\Identity\Application\Query\ListWishlistItems;
use App\Shared\Application\Pagination\Page;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class WishlistFinder
{
    /** Paginated list on a bounded per-customer set: one filtered `COUNT` plus one `LIMIT/OFFSET` page. */
    private const array ORDER_BY = [
        'recent' => 'w.created_at DESC, p.slug ASC',
        'name' => 'p.name ASC, p.slug ASC',
        'price_asc' => 'p.price_minor ASC, p.slug ASC',
        'price_desc' => 'p.price_minor DESC, p.slug ASC',
    ];

    public function __construct(private Connection $connection)
    {
    }

    #[AsMessageHandler(bus: 'query.bus')]
    public function handleListWishlistItems(ListWishlistItems $query): Page
    {
        $conditions = ['w.customer_id = :customer'];
        $params = ['customer' => $query->customerId];

        if (null !== $query->q) {
            $conditions[] = "(p.name ILIKE :q ESCAPE '\\' OR p.brand ILIKE :q ESCAPE '\\')";
            $params['q'] = '%'.self::escapeLike($query->q).'%';
        }

        $where = implode(' AND ', $conditions);

        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM storefront_wishlist_item w
             INNER JOIN storefront_product_view p ON p.slug = w.product_slug
             WHERE '.$where,
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.*
             FROM storefront_wishlist_item w
             INNER JOIN storefront_product_view p ON p.slug = w.product_slug
             WHERE '.$where.'
             ORDER BY '.self::ORDER_BY[$query->effectiveSort()].'
             LIMIT '.$query->page->effectiveSize().' OFFSET '.$query->page->effectiveOffset(),
            $params,
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = StorefrontProductView::fromRow($row)->toApi();
        }

        return new Page($items, $query->page->effectivePage(), $query->page->effectiveSize(), $total);
    }

    private static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
