<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\AddCategorySubLevel;
use App\Catalog\Application\Port\CatalogAuditPort;
use App\Catalog\Application\Port\CatalogSubmissionReviewer;
use App\Catalog\Application\Port\CategoryReader;
use App\Catalog\Application\Port\CategoryTreeWriter;
use App\Catalog\Application\Service\SubmissionAccessPolicy;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\CategoryNotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Adds one node to the `subcategories` JSON tree of the parent row (spec R2, ADR-0006):
 * no child row is created, and a refused label leaves the parent exactly as it was.
 *
 * Taxonomy writes (Admin or Super Admin) go through the same access guard as a full tree rewrite
 * and are applied as a reviewer save so the category stays approved for the storefront.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class AddCategorySubLevelHandler
{
    public function __construct(
        private readonly CategoryReader $categories,
        private readonly CategoryTreeWriter $trees,
        private readonly SubmissionAccessPolicy $access,
        private readonly CatalogAuditPort $audit,
        private readonly CatalogSubmissionReviewer $submissions,
    ) {
    }

    public function __invoke(AddCategorySubLevel $command): void
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
        $category = $category->withSubLevel($command->label, $command->parentLabel);

        $this->trees->addSubLevel($category);

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
