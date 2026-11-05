<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Projection;

use App\Shared\Application\Projection\ProjectionReplayer;
use Doctrine\DBAL\Connection;

/**
 * Rebuilds `catalog_product_view` from `catalog_products` (spec-run-15 R32, H6, MIG-002).
 *
 * `catalog_products` is the state-based write table for this minimal read model, so a replay is an
 * upsert of every source row and does not read an event store. Only rows that differ are written,
 * which makes the command idempotent and lets it report the real work. A view row whose product is
 * gone is an orphan: it is reported, never deleted, because the operator owns the cleanup decision.
 *
 * The whole replay runs in one transaction. It writes no `outbox` or `processed_events` row: a
 * replay is a read-model repair, not a command, and emits no domain event.
 */
final class ReplayCatalogProductView implements ProjectionReplayer
{
    private const string UPSERT_SQL = <<<'SQL'
        INSERT INTO catalog_product_view (id, name, price_minor, currency)
        SELECT id, name, price_minor, currency
          FROM catalog_products
        ON CONFLICT (id) DO UPDATE
           SET name = EXCLUDED.name,
               price_minor = EXCLUDED.price_minor,
               currency = EXCLUDED.currency
         WHERE catalog_product_view.name IS DISTINCT FROM EXCLUDED.name
            OR catalog_product_view.price_minor IS DISTINCT FROM EXCLUDED.price_minor
            OR catalog_product_view.currency IS DISTINCT FROM EXCLUDED.currency
        SQL;

    /** What the upsert would write on this run; the dry-run reads this instead of writing. */
    private const string PENDING_SQL = <<<'SQL'
        SELECT count(*)
          FROM catalog_products p
          LEFT JOIN catalog_product_view v ON v.id = p.id
         WHERE v.id IS NULL
            OR v.name IS DISTINCT FROM p.name
            OR v.price_minor IS DISTINCT FROM p.price_minor
            OR v.currency IS DISTINCT FROM p.currency
        SQL;

    private const string ORPHANS_SQL = <<<'SQL'
        SELECT count(*)
          FROM catalog_product_view v
          LEFT JOIN catalog_products p ON p.id = v.id
         WHERE p.id IS NULL
        SQL;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function name(): string
    {
        return 'catalog-product-view';
    }

    public function source(): string
    {
        return 'catalog_products';
    }

    public function replay(bool $dryRun): array
    {
        /** @var array{written: int, orphans: int} $report */
        $report = $this->connection->transactional(function () use ($dryRun): array {
            $orphans = (int) $this->connection->fetchOne(self::ORPHANS_SQL);

            if ($dryRun) {
                return [
                    'written' => (int) $this->connection->fetchOne(self::PENDING_SQL),
                    'orphans' => $orphans,
                ];
            }

            return [
                'written' => $this->connection->executeStatement(self::UPSERT_SQL),
                'orphans' => $orphans,
            ];
        });

        return $report;
    }
}
