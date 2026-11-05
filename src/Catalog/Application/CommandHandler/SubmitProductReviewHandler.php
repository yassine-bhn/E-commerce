<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\SubmitProductReview;
use App\Catalog\Application\Port\ProductReviewStore;
use App\Catalog\Application\Port\ProductVisibilityPort;
use App\Catalog\Domain\Model\ProductNotReviewableException;
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
        private readonly ProductVisibilityPort $visibility,
        private readonly ProductReviewStore $reviews,
    ) {
    }

    public function __invoke(SubmitProductReview $command): void
    {
        if (!$this->visibility->isPubliclyVisible($command->slug)) {
            throw ProductNotReviewableException::forSlug($command->slug);
        }

        $this->reviews->store(
            $command->id,
            $command->slug,
            $command->authorName,
            $command->rating,
            $command->comment,
        );
    }
}
