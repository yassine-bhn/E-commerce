<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence;

use App\Catalog\Application\Port\ProductDeletionReader;
use App\Catalog\Application\Port\ProductPublicationStore;
use App\Catalog\Application\Port\ProductReviewStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * DIP-003 — the admin product writes behind narrow ports: the publication flag (the one writer,
 * R28), the public review insert and the order-history count.
 */
final class DbalAdminProductStore implements ProductPublicationStore, ProductReviewStore, ProductDeletionReader
{
    public function __construct(private Connection $connection) {
    }

    public function setPublished(string $slug, bool $published): void
    {
        $this->connection->executeStatement(
            'UPDATE storefront_product_view SET published = :published WHERE slug = :slug',
            ['published' => $published, 'slug' => $slug],
            ['published' => ParameterType::BOOLEAN],
        );
    }

    public function store(string $id, string $slug, string $authorName, int $rating, string $comment): void
    {
        $this->connection->executeStatement(
            'INSERT INTO storefront_product_review (id, product_slug, author_name, rating, comment)
             VALUES (:id, :slug, :author, :rating, :comment)',
            [
                'id' => $id,
                'slug' => $slug,
                'author' => $authorName,
                'rating' => max(1, min(5, $rating)),
                'comment' => $comment,
            ],
        );
    }

    public function orderCount(string $slug): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(DISTINCT order_id) FROM ordering_order_lines WHERE product_slug = :slug',
            ['slug' => $slug],
        );
    }
}
