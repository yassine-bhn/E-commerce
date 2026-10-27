<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\ReplaceCategoryTree;
use App\Catalog\Application\Port\CatalogAuditPort;
use App\Catalog\Application\Service\SubmissionAccessPolicy;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\Category;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Rewrites the editable content of a category (spec R3): label, quote eligibility and the
 * whole `subcategories` JSON tree of that row.
 *
 * Visibility is deliberately not part of this statement — hiding is its own command — and
 * a tree that would drop a sub-level a product still points at is refused before anything
 * is written, so the catalog can never disagree with an order snapshot (ECO-006).
 *
 * spec-run-8 R7/R8/R25/R35/R40 — the row is read through {@see SubmissionAccessPolicy} first,
 * so an Admin can only rewrite their own pending or rejected submission; a Super Admin's own save
 * is the validation, so the record comes out approved instead of being queued for him.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class ReplaceCategoryTreeHandler
{
    private const LOAD = 'SELECT slug, label, subcategories, quote_eligible, hidden, sort_order, parent_slug
         FROM storefront_category WHERE slug = :slug';

    private const USED_SUB_LEVELS = "SELECT DISTINCT subcategory
         FROM storefront_product_view
         WHERE category_slug = :slug
           AND subcategory IS NOT NULL
           AND subcategory <> ''
         ORDER BY subcategory ASC";

    private const STORE = 'UPDATE storefront_category SET label = :label, quote_eligible = :quote, subcategories = :subs WHERE slug = :slug';

    private const STORE_REOPEN = 'UPDATE storefront_category SET label = :label, quote_eligible = :quote, subcategories = :subs,
            approval_status = :status, reviewed_by = :reviewed_by, reviewed_at = :reviewed_at, rejection_reason = NULL
         WHERE slug = :slug';

    public function __construct(
        private readonly Connection $connection,
        private readonly SubmissionAccessPolicy $access,
        private readonly CatalogAuditPort $audit,
    ) {
    }

    public function __invoke(ReplaceCategoryTree $command): void
    {
        $this->connection->transactional(function () use ($command): void {
            $current = $this->access->authorize(
                CatalogSubmissionKind::Category,
                $command->slug,
                $command->actorId,
                $command->actorIsSuperAdmin,
            );

            $category = Category::fromStoredRow(
                $this->connection->fetchAssociative(self::LOAD, ['slug' => $command->slug]),
                $command->slug,
            )->withContent($command->label, $command->subcategories, $command->quoteEligible);

            $category->coversSubLevels($this->usedSubLevels($category->slug));

            $next = $command->actorIsSuperAdmin
                ? $current->afterReviewerModification()
                : $current->afterModification();
            $reopens = $next !== $current;

            $this->connection->executeStatement(
                $reopens ? self::STORE_REOPEN : self::STORE,
                [
                    'label' => $category->label,
                    'quote' => $category->quoteEligible,
                    'subs' => $category->subcategoriesJson(),
                    'slug' => $category->slug,
                ] + ($reopens ? [
                    'status' => $next->value,
                    'reviewed_by' => $command->actorIsSuperAdmin ? $command->actorId : null,
                    'reviewed_at' => $command->actorIsSuperAdmin ? (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP') : null,
                ] : []),
                ['quote' => ParameterType::BOOLEAN],
            );

            $this->audit->record(
                'CATALOG_CATEGORY_UPDATED',
                CatalogSubmissionKind::Category->auditTarget(),
                $category->slug,
                $command->actorId ?? 'system',
            );
        });
    }

    /**
     * The sub-level labels the products of this category currently point at.
     *
     * @return list<string>
     */
    private function usedSubLevels(string $slug): array
    {
        $labels = [];
        foreach ($this->connection->fetchAllAssociative(self::USED_SUB_LEVELS, ['slug' => $slug]) as $row) {
            $label = trim((string) ($row['subcategory'] ?? ''));
            if ('' !== $label) {
                $labels[] = $label;
            }
        }

        return array_values(array_unique($labels));
    }
}
