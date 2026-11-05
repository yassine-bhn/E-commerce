<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\DeleteCategory;
use App\Catalog\Application\Port\CatalogAuditPort;
use App\Catalog\Application\Port\CatalogSubmissionDeleter;
use App\Catalog\Application\Port\CategoryDeleter;
use App\Catalog\Application\Port\CategoryDeletionReader;
use App\Catalog\Application\Port\CategoryReader;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\CategoryNotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deletes a category row (spec R4) — and only an empty one.
 *
 * The children counted here are the nodes of this row's `subcategories` JSON tree plus the
 * legacy `parent_slug` rows a pre-Q1 taxonomy may still own, never the products themselves:
 * a category holding a product is refused, so no order line can lose its category.
 *
 * spec-run-8 R20/R42/R45 — the refusal happens before anything is written, and the deletion and
 * its audit row commit together, so a refused delete leaves both the row and the history intact.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class DeleteCategoryHandler
{
    public function __construct(
        private readonly CategoryReader $categories,
        private readonly CategoryDeletionReader $deletionReads,
        private readonly CategoryDeleter $deletions,
        private readonly CatalogSubmissionDeleter $submissions,
        private readonly CatalogAuditPort $audit,
    ) {
    }

    public function __invoke(DeleteCategory $command): void
    {
        $category = $this->categories->find($command->slug);
        if (null === $category) {
            throw CategoryNotFoundException::forSlug($command->slug);
        }

        $counts = $this->deletionReads->counts($category->slug);

        $category->ensureDeletable(
            $counts['children'],
            $counts['products'],
        );

        $this->deletions->delete($category->slug);

        // Slug reuse must not inherit a previous approval (R11).
        $this->submissions->delete(CatalogSubmissionKind::Category, $category->slug);

        $this->audit->record(
            'CATALOG_CATEGORY_DELETED',
            CatalogSubmissionKind::Category->auditTarget(),
            $category->slug,
            $command->actorId ?? 'system',
        );
    }
}
