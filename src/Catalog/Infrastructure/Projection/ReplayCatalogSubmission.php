<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Projection;

use App\Shared\Application\Projection\ProjectionReplayer;
use Doctrine\DBAL\Connection;

/**
 * spec-run-15 WP2 / R31 — rebuilds the six approval columns of both storefront views from
 * `catalog_submission`, the authority introduced for the approval workflow.
 *
 * The replay copies state, it does not insert content: a submission without a view row is reported
 * as `missingView`, and a view row without a submission is reported as an orphan, because inventing
 * or deleting catalog content is not a read-model repair. Only rows whose columns actually differ
 * are written, which makes a second run a no-op and keeps `published`/`hidden` untouched. The whole
 * run is one transaction with no `outbox` or `processed_events` write.
 */
final class ReplayCatalogSubmission implements ProjectionReplayer
{
    private const string COLUMNS = 'approval_status = s.approval_status,
            submitted_by = s.submitted_by,
            submitted_at = s.submitted_at,
            reviewed_by = s.reviewed_by,
            reviewed_at = s.reviewed_at,
            rejection_reason = s.rejection_reason';

    private const string DIFFERS = 'v.approval_status IS DISTINCT FROM s.approval_status
            OR v.submitted_by IS DISTINCT FROM s.submitted_by
            OR v.submitted_at IS DISTINCT FROM s.submitted_at
            OR v.reviewed_by IS DISTINCT FROM s.reviewed_by
            OR v.reviewed_at IS DISTINCT FROM s.reviewed_at
            OR v.rejection_reason IS DISTINCT FROM s.rejection_reason';

    private const string PENDING_PRODUCTS_SQL = <<<'SQL'
        SELECT count(*)
          FROM catalog_submission s
          JOIN storefront_product_view v ON s.kind = 'products' AND v.slug = s.slug
         WHERE v.approval_status IS DISTINCT FROM s.approval_status
            OR v.submitted_by IS DISTINCT FROM s.submitted_by
            OR v.submitted_at IS DISTINCT FROM s.submitted_at
            OR v.reviewed_by IS DISTINCT FROM s.reviewed_by
            OR v.reviewed_at IS DISTINCT FROM s.reviewed_at
            OR v.rejection_reason IS DISTINCT FROM s.rejection_reason
        SQL;

    private const string PENDING_CATEGORIES_SQL = <<<'SQL'
        SELECT count(*)
          FROM catalog_submission s
          JOIN storefront_category v ON s.kind = 'categories' AND v.slug = s.slug
         WHERE v.approval_status IS DISTINCT FROM s.approval_status
            OR v.submitted_by IS DISTINCT FROM s.submitted_by
            OR v.submitted_at IS DISTINCT FROM s.submitted_at
            OR v.reviewed_by IS DISTINCT FROM s.reviewed_by
            OR v.reviewed_at IS DISTINCT FROM s.reviewed_at
            OR v.rejection_reason IS DISTINCT FROM s.rejection_reason
        SQL;

    private const string ORPHANS_SQL = <<<'SQL'
        SELECT (SELECT count(*)
                  FROM storefront_product_view v
                  LEFT JOIN catalog_submission s ON s.kind = 'products' AND s.slug = v.slug
                 WHERE s.slug IS NULL)
             + (SELECT count(*)
                  FROM storefront_category v
                  LEFT JOIN catalog_submission s ON s.kind = 'categories' AND s.slug = v.slug
                 WHERE s.slug IS NULL)
        SQL;

    private const string MISSING_VIEW_SQL = <<<'SQL'
        SELECT (SELECT count(*)
                  FROM catalog_submission s
                  LEFT JOIN storefront_product_view v ON s.kind = 'products' AND v.slug = s.slug
                 WHERE s.kind = 'products' AND v.slug IS NULL)
             + (SELECT count(*)
                  FROM catalog_submission s
                  LEFT JOIN storefront_category v ON s.kind = 'categories' AND v.slug = s.slug
                 WHERE s.kind = 'categories' AND v.slug IS NULL)
        SQL;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function name(): string
    {
        return 'catalog-submission';
    }

    public function source(): string
    {
        return 'catalog_submission';
    }

    public function replay(bool $dryRun): array
    {
        /** @var array{written: int, orphans: int, missingView: int} $report */
        $report = $this->connection->transactional(function () use ($dryRun): array {
            $orphans = (int) $this->connection->fetchOne(self::ORPHANS_SQL);
            $missingView = (int) $this->connection->fetchOne(self::MISSING_VIEW_SQL);

            if ($dryRun) {
                return [
                    'written' => $this->pending(),
                    'orphans' => $orphans,
                    'missingView' => $missingView,
                ];
            }

            return [
                'written' => $this->updateProducts() + $this->updateCategories(),
                'orphans' => $orphans,
                'missingView' => $missingView,
            ];
        });

        return $report;
    }

    private function pending(): int
    {
        return (int) $this->connection->fetchOne(self::PENDING_PRODUCTS_SQL)
            + (int) $this->connection->fetchOne(self::PENDING_CATEGORIES_SQL);
    }

    private function updateProducts(): int
    {
        return (int) $this->connection->executeStatement(sprintf(
            'UPDATE storefront_product_view v SET %s FROM catalog_submission s
              WHERE s.kind = \'products\' AND s.slug = v.slug AND (%s)',
            self::COLUMNS,
            self::DIFFERS,
        ));
    }

    private function updateCategories(): int
    {
        return (int) $this->connection->executeStatement(sprintf(
            'UPDATE storefront_category v SET %s FROM catalog_submission s
              WHERE s.kind = \'categories\' AND s.slug = v.slug AND (%s)',
            self::COLUMNS,
            self::DIFFERS,
        ));
    }
}
