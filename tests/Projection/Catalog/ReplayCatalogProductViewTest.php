<?php

declare(strict_types=1);

namespace App\Tests\Projection\Catalog;

use App\Catalog\Infrastructure\Projection\ReplayCatalogProductView;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * spec-run-15 R31/R32 / MIG-002 — `catalog-product-view` is rebuilt from `catalog_products`.
 *
 * The replay is an upsert of the state-based write table, not an event replay: it restores a
 * corrupted or missing view row, reports orphans without deleting them, writes nothing on a second
 * run and never touches `outbox` or `processed_events`.
 */
final class ReplayCatalogProductViewTest extends KernelTestCase
{
    private ?Connection $connection = null;

    private ?ReplayCatalogProductView $replayer = null;

    /** @var list<string> */
    private array $productIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->connection = $connection;

        /** @var ReplayCatalogProductView $replayer */
        $replayer = $container->get(ReplayCatalogProductView::class);
        $this->replayer = $replayer;
    }

    protected function tearDown(): void
    {
        if (null !== $this->connection) {
            foreach ($this->productIds as $productId) {
                $this->connection->executeStatement('DELETE FROM catalog_product_view WHERE id = :id', ['id' => $productId]);
                $this->connection->executeStatement('DELETE FROM catalog_products WHERE id = :id', ['id' => $productId]);
            }
        }

        parent::tearDown();
    }

    public function testItRestoresACorruptedRowAndInsertsAMissingRow(): void
    {
        $this->requireSchema();

        $corruptedId = $this->newProductId();
        $missingId = $this->newProductId();

        $this->insertSource($corruptedId, 'Source Widget', 1530, 'TND');
        $this->insertView($corruptedId, 'Corrupted Widget', 99, 'EUR');
        $this->insertSource($missingId, 'Missing Widget', 680000, 'TND');

        $pending = $this->countPending();
        $orphans = $this->countOrphans();

        $report = $this->replayer()->replay(false);

        self::assertSame($pending, $report['written'], 'every source row that differs from its view row is written');
        self::assertSame($orphans, $report['orphans']);
        self::assertSame('Source Widget', $this->viewValue($corruptedId, 'name'));
        self::assertSame(1530, (int) $this->viewValue($corruptedId, 'price_minor'));
        self::assertSame('TND', $this->viewValue($corruptedId, 'currency'));
        self::assertSame('Missing Widget', $this->viewValue($missingId, 'name'), 'a missing view row is inserted');

        self::assertSame(0, $this->outboxRowsFor($corruptedId) + $this->outboxRowsFor($missingId), 'a replay is not a command and emits no domain event');
        self::assertSame(0, $this->replayClaims(), 'a replay writes no processed_events row');
    }

    public function testASecondReplayWritesNothing(): void
    {
        $this->requireSchema();

        $productId = $this->newProductId();
        $this->insertSource($productId, 'Stable Widget', 1000, 'TND');
        $this->insertView($productId, 'Stale Widget', 1, 'EUR');

        $first = $this->replayer()->replay(false);
        self::assertGreaterThanOrEqual(1, $first['written']);

        $second = $this->replayer()->replay(false);

        self::assertSame(0, $second['written'], 'R31: a second replay changes nothing');
        self::assertSame('Stable Widget', $this->viewValue($productId, 'name'));
        self::assertSame(1000, (int) $this->viewValue($productId, 'price_minor'));
    }

    public function testDryRunReportsTheWorkWithoutWriting(): void
    {
        $this->requireSchema();

        $productId = $this->newProductId();
        $this->insertSource($productId, 'Dry Run Widget', 2500, 'TND');
        $this->insertView($productId, 'Still Corrupted', 1, 'EUR');

        $pending = $this->countPending();
        $report = $this->replayer()->replay(true);

        self::assertSame($pending, $report['written'], 'the dry run reports the rows it would write');
        self::assertSame('Still Corrupted', $this->viewValue($productId, 'name'), 'R30: --dry-run must not write');
        self::assertSame(1, (int) $this->viewValue($productId, 'price_minor'));
    }

    public function testOrphanViewRowsAreReportedAndLeftInPlace(): void
    {
        $this->requireSchema();

        $orphanId = $this->newProductId();
        $this->insertView($orphanId, 'Orphan Widget', 500, 'TND');

        $orphans = $this->countOrphans();
        $report = $this->replayer()->replay(false);

        self::assertSame($orphans, $report['orphans']);
        self::assertGreaterThanOrEqual(1, $report['orphans'], 'R32: a view row with no source row is an orphan');
        self::assertSame('Orphan Widget', $this->viewValue($orphanId, 'name'), 'R32: orphans are reported, never deleted');
    }

    private function requireSchema(): void
    {
        try {
            $this->connection()->executeQuery('SELECT 1 FROM catalog_products LIMIT 1');
            $this->connection()->executeQuery('SELECT 1 FROM catalog_product_view LIMIT 1');
            $this->connection()->executeQuery('SELECT 1 FROM outbox LIMIT 1');
            $this->connection()->executeQuery('SELECT 1 FROM processed_events LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('PostgreSQL schema not migrated (run doctrine:migrations:migrate).');
        }
    }

    private function insertSource(string $id, string $name, int $priceMinor, string $currency): void
    {
        $this->connection()->insert('catalog_products', [
            'id' => $id,
            'name' => $name,
            'price_minor' => $priceMinor,
            'currency' => $currency,
        ]);
    }

    private function insertView(string $id, string $name, int $priceMinor, string $currency): void
    {
        $this->connection()->insert('catalog_product_view', [
            'id' => $id,
            'name' => $name,
            'price_minor' => $priceMinor,
            'currency' => $currency,
        ]);
    }

    private function countPending(): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT count(*) FROM catalog_products p
               LEFT JOIN catalog_product_view v ON v.id = p.id
              WHERE v.id IS NULL
                 OR v.name IS DISTINCT FROM p.name
                 OR v.price_minor IS DISTINCT FROM p.price_minor
                 OR v.currency IS DISTINCT FROM p.currency',
        );
    }

    private function countOrphans(): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT count(*) FROM catalog_product_view v
               LEFT JOIN catalog_products p ON p.id = v.id
              WHERE p.id IS NULL',
        );
    }

    private function viewValue(string $productId, string $column): mixed
    {
        return $this->connection()->fetchOne(
            sprintf('SELECT %s FROM catalog_product_view WHERE id = :id', $column),
            ['id' => $productId],
        );
    }

    private function outboxRowsFor(string $aggregateId): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT count(*) FROM outbox WHERE aggregate_id = :id',
            ['id' => $aggregateId],
        );
    }

    private function replayClaims(): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT count(*) FROM processed_events WHERE consumer = :consumer',
            ['consumer' => ReplayCatalogProductView::class],
        );
    }

    private function newProductId(): string
    {
        $productId = Uuid::v4()->toRfc4122();
        $this->productIds[] = $productId;

        return $productId;
    }

    private function connection(): Connection
    {
        $connection = $this->connection;
        if (!$connection instanceof Connection) {
            throw new \LogicException('The DBAL connection was not bootstrapped by setUp().');
        }

        return $connection;
    }

    private function replayer(): ReplayCatalogProductView
    {
        $replayer = $this->replayer;
        if (!$replayer instanceof ReplayCatalogProductView) {
            throw new \LogicException('The replayer was not resolved from the container by setUp().');
        }

        return $replayer;
    }
}
