<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\AddCategorySubLevel;
use App\Catalog\Application\Port\CatalogAuditPort;
use App\Catalog\Application\Service\SubmissionAccessPolicy;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\Category;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Adds one node to the `subcategories` JSON tree of the parent row (spec R2, ADR-0006):
 * no child row is created, and a refused label leaves the parent exactly as it was.
 *
 * spec-run-8 R35/R40 — the write goes through the same ownership guard as a full tree rewrite,
 * and any staff edit of an approved row reopens it for review.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class AddCategorySubLevelHandler
{
    private const LOAD = 'SELECT slug, label, subcategories, quote_eligible, hidden, sort_order, parent_slug
         FROM storefront_category WHERE slug = :slug';

    private const STORE = 'UPDATE storefront_category SET subcategories = :subs WHERE slug = :slug';

    private const STORE_REOPEN = 'UPDATE storefront_category SET subcategories = :subs,
            approval_status = :status, reviewed_by = NULL, reviewed_at = NULL, rejection_reason = NULL
         WHERE slug = :slug';

    public function __construct(
        private readonly Connection $connection,
        private readonly SubmissionAccessPolicy $access,
        private readonly CatalogAuditPort $audit,
    ) {
    }

    public function __invoke(AddCategorySubLevel $command): void
    {
        $this->connection->transactional(function () use ($command): void {
            $current = $this->access->authorize(
                CatalogSubmissionKind::Category,
                $command->slug,
                $command->actorId,
                $command->actorIsAdmin,
            );

            $category = Category::fromStoredRow(
                $this->connection->fetchAssociative(self::LOAD, ['slug' => $command->slug]),
                $command->slug,
            )->withSubLevel($command->label, $command->parentLabel);

            $next = $current->afterModification();
            $reopens = $next !== $current;

            $this->connection->executeStatement(
                $reopens ? self::STORE_REOPEN : self::STORE,
                ['subs' => $category->subcategoriesJson(), 'slug' => $category->slug]
                    + ($reopens ? ['status' => $next->value] : []),
            );

            $this->audit->record(
                'CATALOG_CATEGORY_UPDATED',
                CatalogSubmissionKind::Category->auditTarget(),
                $category->slug,
                $command->actorId ?? 'system',
            );
        });
    }
}
