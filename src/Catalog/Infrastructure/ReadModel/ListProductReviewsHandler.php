<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

use App\Catalog\Application\Query\ListProductReviews;
use App\Shared\Application\Pagination\Cursor;
use App\Shared\Application\Pagination\TimestampCursor;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * PRF-001 — a reviews page costs exactly two statements: one aggregate over the whole product
 * (count + average, independent of the rating filter) and one keyset page.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class ListProductReviewsHandler
{
    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(ListProductReviews $query): ProductReviewsPage
    {
        $limit = $query->cursor->effectiveLimit();

        $aggregate = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS total, COALESCE(AVG(rating), 0) AS average
             FROM storefront_product_review
             WHERE product_slug = :slug',
            ['slug' => $query->slug],
        );

        $conditions = ['product_slug = :slug'];
        $params = ['slug' => $query->slug];

        if (null !== $query->rating) {
            $conditions[] = 'rating = :rating';
            $params['rating'] = $query->rating;
        }

        $rawCursor = $query->cursor->rawCursor();
        $cursor = TimestampCursor::decode($rawCursor);
        if (null !== $cursor) {
            $conditions[] = '(created_at, id) < (CAST(:cursor_key AS timestamptz), CAST(:cursor_id AS uuid))';
            $params['cursor_key'] = $cursor['key'];
            $params['cursor_id'] = $cursor['id'];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, author_name, rating, comment, created_at
             FROM storefront_product_review
             WHERE '.implode(' AND ', $conditions).'
             ORDER BY created_at DESC, id DESC
             LIMIT '.($limit + 1),
            $params,
        );

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        $nextCursor = null;
        if ($hasMore && [] !== $rows) {
            $last = $rows[array_key_last($rows)];
            $nextCursor = Cursor::encode((string) $last['created_at'], (string) $last['id']);
        }

        return new ProductReviewsPage(
            array_map(static fn (array $row): array => [
                'id' => (string) $row['id'],
                'author' => (string) $row['author_name'],
                'rating' => (int) $row['rating'],
                'comment' => (string) $row['comment'],
                'date' => substr((string) $row['created_at'], 0, 10),
            ], $rows),
            $limit,
            $hasMore,
            $nextCursor,
            (int) ($aggregate['total'] ?? 0),
            round((float) ($aggregate['average'] ?? 0), 1),
        );
    }
}
