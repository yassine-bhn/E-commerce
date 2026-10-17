<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\SubmitProductReview;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class SubmitProductReviewHandler
{
    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(SubmitProductReview $command): void
    {
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
