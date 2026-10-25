<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\DeleteCategory;
use App\Catalog\Application\Port\CatalogAuditPort;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\Category;
use Doctrine\DBAL\Connection;
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
    private const LOAD = 'SELECT slug, label, subcategories, quote_eligible, hidden, sort_order, parent_slug
         FROM storefront_category WHERE slug = :slug';

    private const COUNTS = 'SELECT (SELECT COUNT(*) FROM storefront_category WHERE parent_slug = :slug) AS child_rows,
            (SELECT COUNT(*) FROM storefront_product_view WHERE category_slug = :slug) AS products';

    private const STORE = 'DELETE FROM storefront_category WHERE slug = :slug';

    public function __construct(
        private readonly Connection $connection,
        private readonly CatalogAuditPort $audit,
    ) {
    }

    public function __invoke(DeleteCategory $command): void
    {
        $this->connection->transactional(function () use ($command): void {
            $category = Category::fromStoredRow(
                $this->connection->fetchAssociative(self::LOAD, ['slug' => $command->slug]),
                $command->slug,
            );

            $counts = $this->connection->fetchAssociative(self::COUNTS, ['slug' => $category->slug]);

            $category->ensureDeletable(
                (int) ($counts['child_rows'] ?? 0),
                (int) ($counts['products'] ?? 0),
            );

            $this->connection->executeStatement(self::STORE, ['slug' => $category->slug]);

            $this->audit->record(
                'CATALOG_CATEGORY_DELETED',
                CatalogSubmissionKind::Category->auditTarget(),
                $category->slug,
                $command->actorId ?? 'system',
            );
        });
    }
}
