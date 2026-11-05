<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\HideCategory;
use App\Catalog\Application\Port\CategoryReader;
use App\Catalog\Application\Port\CategoryVisibilityWriter;
use App\Catalog\Application\Service\SubmissionAccessPolicy;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\CategoryNotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Hides a category from storefront navigation (spec R5).
 *
 * Only the visibility flag is written: the row, its sub-level tree and its products stay
 * in place, so hiding is always reversible and never destroys catalog data.
 *
 * spec-run-8 R35 — a regular Admin can only hide their own submission; hiding an approved
 * category stays with the Super Admin, who is the only one allowed to write approved rows.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class HideCategoryHandler
{
    public function __construct(
        private readonly CategoryReader $categories,
        private readonly CategoryVisibilityWriter $visibility,
        private readonly SubmissionAccessPolicy $access,
    ) {
    }

    public function __invoke(HideCategory $command): void
    {
        $this->access->authorize(
            CatalogSubmissionKind::Category,
            $command->slug,
            $command->actorId,
            $command->actorIsSuperAdmin,
        );

        $category = $this->categories->find($command->slug);
        if (null === $category) {
            throw CategoryNotFoundException::forSlug($command->slug);
        }

        $this->visibility->setHidden($category->slug, $category->hide()->isHidden());
    }
}
