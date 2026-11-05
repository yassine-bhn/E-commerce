<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Projection;

use App\Shared\Application\Projection\ProjectionReplayer;
use Doctrine\DBAL\Connection;

/**
 * spec-run-15 WP4 (R19/R31) — rebuilds `storefront_product_view.stock_quantity` from the balance.
 *
 * The balance is the authority, so the replay copies `on_hand - reserved` into the cache. It writes
 * only rows whose value actually differs (a second run reports zero), never inserts a view row,
 * never turns an untracked row (`stock_quantity IS NULL`) into a tracked one, and never writes
 * `outbox`/`processed_events`: a replay repairs a read model, it is not a command.
 *
 * Both directions are reported for the operator: a tracked view row whose balance is gone
 * (`orphans`, left in place) and a balance whose view row is gone (`missingView`, never invented).
 */
final class ReplayInventoryStock implements ProjectionReplayer
{
    private const string PENDING_SQL = <<<'SQL'
        SELECT COUNT(*)
          FROM storefront_product_view v
          JOIN inventory_stock s ON s.product_slug = v.slug
         WHERE v.stock_quantity IS NOT NULL
           AND v.stock_quantity IS DISTINCT FROM s.on_hand - s.reserved
        SQL;

    private const string UPDATE_SQL = <<<'SQL'
        UPDATE storefront_product_view v
           SET stock_quantity = s.on_hand - s.reserved
          FROM inventory_stock s
         WHERE s.product_slug = v.slug
           AND v.stock_quantity IS NOT NULL
           AND v.stock_quantity IS DISTINCT FROM s.on_hand - s.reserved
        SQL;

    private const string ORPHANS_SQL = <<<'SQL'
        SELECT COUNT(*)
          FROM storefront_product_view v
          LEFT JOIN inventory_stock s ON s.product_slug = v.slug
         WHERE v.stock_quantity IS NOT NULL AND s.product_slug IS NULL
        SQL;

    private const string MISSING_VIEW_SQL = <<<'SQL'
        SELECT COUNT(*)
          FROM inventory_stock s
          LEFT JOIN storefront_product_view v ON v.slug = s.product_slug
         WHERE v.slug IS NULL
        SQL;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function name(): string
    {
        return 'inventory-stock';
    }

    public function source(): string
    {
        return 'inventory_stock';
    }

    public function replay(bool $dryRun): array
    {
        /** @var array{written: int, orphans: int, missingView: int} $report */
        $report = $this->connection->transactional(function () use ($dryRun): array {
            $orphans = (int) $this->connection->fetchOne(self::ORPHANS_SQL);
            $missingView = (int) $this->connection->fetchOne(self::MISSING_VIEW_SQL);

            return [
                'written' => $dryRun
                    ? (int) $this->connection->fetchOne(self::PENDING_SQL)
                    : (int) $this->connection->executeStatement(self::UPDATE_SQL),
                'orphans' => $orphans,
                'missingView' => $missingView,
            ];
        });

        return $report;
    }
}
