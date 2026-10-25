<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\SubmitProductReview;
use App\Catalog\Application\Port\ProductVisibilityPort;
use App\Catalog\Domain\Model\ProductNotReviewableException;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A review is public content about a product, so the write is bound to the same visibility as the
 * product detail: the predicate is checked here, where the row is written, and answered as
 * not-found (spec-run-12 R17, QRY-004).
 */
#[AsMessageHandler(bus: 'command.bus')]
final class SubmitProductReviewHandler
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ProductVisibilityPort $visibility,
    ) {
    }

    public function __invoke(SubmitProductReview $command): void
    {
        if (!$this->visibility->isPubliclyVisible($command->slug)) {
            throw ProductNotReviewableException::forSlug($command->slug);
        }

        $this->connection->executeStatement(
            'INSERT INTO storefront_product_review (id, product_slug, author_name, rating, comment)
             VALUES (:id, :slug, :author, :rating, :comment)',
            [
                'id' => $command->id,
                'slug' => $command->slug,
                'author' => $command->authorName,
                'rating' => max(1, min(5, $command->rating)),
                'comment' => $command->comment,
            ],
        );
    }
}
