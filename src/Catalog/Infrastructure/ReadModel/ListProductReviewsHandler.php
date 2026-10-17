<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

use App\Catalog\Application\Query\ListProductReviews;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class ListProductReviewsHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<array<string, mixed>> */
    public function __invoke(ListProductReviews $query): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, author_name, rating, comment, created_at
             FROM storefront_product_review
             WHERE product_slug = :slug
             ORDER BY created_at DESC',
            ['slug' => $query->slug],
        );

        return array_map(static fn (array $r): array => [
            'id' => (string) $r['id'],
            'author' => (string) $r['author_name'],
            'rating' => (int) $r['rating'],
            'comment' => (string) $r['comment'],
            'date' => substr((string) $r['created_at'], 0, 10),
        ], $rows);
    }
}
