<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Port\CatalogSubmissionCreator;
use App\Catalog\Application\Port\CatalogSubmissionReviewer;
use App\Catalog\Application\Port\CategoryCreator;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\Category;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Writes the new category row (spec R1). The handler is the transaction boundary
 * (CMD-005): the row is inserted once, visible by default, and a slug that is already
 * taken leaves nothing behind.
 *
 * Admin and Super Admin taxonomy creates are approved immediately so the category (and its
 * sub-levels) can appear on the storefront without a second approval step.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class CreateCategoryHandler
{
    public function __construct(
        private readonly CategoryCreator $categories,
        private readonly CatalogSubmissionCreator $submissions,
        private readonly CatalogSubmissionReviewer $reviews,
    ) {
    }

    public function __invoke(CreateCategory $command): void
    {
        $category = Category::createRoot(
            slug: $command->slug,
            label: $command->label,
            subcategories: $command->subcategories,
            quoteEligible: $command->quoteEligible,
        );

        $this->categories->create($category);

        $actorId = $command->actorId ?? 'system';
        $this->submissions->create(
            CatalogSubmissionKind::Category,
            $category->slug,
            $actorId,
        );
        $this->reviews->reviewerSave(
            CatalogSubmissionKind::Category,
            $category->slug,
            $actorId,
        );
    }
}
