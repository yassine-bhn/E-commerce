<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\ReplaceCategoryTree;
use App\Catalog\Application\Port\CatalogAuditPort;
use App\Catalog\Application\Port\CatalogSubmissionReviewer;
use App\Catalog\Application\Port\CategoryReader;
use App\Catalog\Application\Port\CategoryTreeWriter;
use App\Catalog\Application\Service\SubmissionAccessPolicy;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\CategoryNotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Rewrites the editable content of a category (spec R3): label, quote eligibility and the
 * whole `subcategories` JSON tree of that row.
 *
 * Visibility is deliberately not part of this statement — hiding is its own command — and
 * a tree that would drop a sub-level a product still points at is refused before anything
 * is written, so the catalog can never disagree with an order snapshot (ECO-006).
 *
 * Taxonomy writes (Admin or Super Admin) go through {@see SubmissionAccessPolicy} and are
 * applied as a reviewer save so the category tree stays approved for the storefront.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class ReplaceCategoryTreeHandler
{
    public function __construct(
        private readonly CategoryReader $categories,
        private readonly CategoryTreeWriter $trees,
        private readonly SubmissionAccessPolicy $access,
        private readonly CatalogAuditPort $audit,
        private readonly CatalogSubmissionReviewer $submissions,
    ) {
    }

    public function __invoke(ReplaceCategoryTree $command): void
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
        $category = $category->withContent($command->label, $command->subcategories, $command->quoteEligible);

        $category->coversSubLevels($this->categories->usedSubLevels($category->slug));

        $this->trees->replaceContent($category);

        $this->audit->record(
            'CATALOG_CATEGORY_UPDATED',
            CatalogSubmissionKind::Category->auditTarget(),
            $category->slug,
            $command->actorId ?? 'system',
        );

        $this->submissions->reviewerSave(
            CatalogSubmissionKind::Category,
            $category->slug,
            $command->actorId ?? 'system',
        );
    }
}
