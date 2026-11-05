<?php

declare(strict_types=1);

namespace App\Tests\Projection\Catalog;

use App\Catalog\Infrastructure\Projection\ReplayCatalogSubmission;
use App\Shared\Application\Projection\ProjectionReplayer;
use App\Shared\Infrastructure\Console\ProjectionReplayCommand;
use App\Shared\Infrastructure\Projection\ProjectionRegistry;
use App\Tests\Integration\Catalog\Support\CatalogRow;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * spec-run-15 WP2 / R31 — `catalog-submission` rebuilds the six approval columns of both views.
 *
 * `catalog_submission` is the authority, so a replay copies it into `storefront_product_view` and
 * `storefront_category` without inventing view rows, without touching `published`/`hidden`, and
 * without emitting an event. Only real differences count as writes, which is what makes a second
 * run report zero.
 */
final class ReplayCatalogSubmissionTest extends KernelTestCase
{
    private const string PRODUCT = 'it-replay-submission-product';
    private const string CATEGORY = 'it-replay-submission-category';
    private const string MISSING = 'it-replay-submission-missing';
    private const string ORPHAN_PRODUCT = 'it-replay-submission-orphan-product';
    private const string ORPHAN_CATEGORY = 'it-replay-submission-orphan-category';

    private const array SLUGS = [
        self::PRODUCT,
        self::CATEGORY,
        self::MISSING,
        self::ORPHAN_PRODUCT,
        self::ORPHAN_CATEGORY,
    ];

    private ?Connection $connection = null;

    private ?ReplayCatalogSubmission $replayer = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->connection = $connection;

        /** @var ReplayCatalogSubmission $replayer */
        $replayer = $container->get(ReplayCatalogSubmission::class);
        $this->replayer = $replayer;

        $this->removeRows();
    }

    protected function tearDown(): void
    {
        $this->removeRows();

        parent::tearDown();
    }

    public function testItRestoresCorruptedRowsForBothKindsAndReportsMissingViews(): void
    {
        $this->insertSubmission(self::PRODUCT, 'products', 'REJECTED', [
            'reviewed_by' => 'reviewer-product',
            'reviewed_at' => '2026-10-29 09:00:00.000000+00',
            'rejection_reason' => 'Blurry photos.',
        ]);
        CatalogRow::product($this->connection(), self::PRODUCT, 'APPROVED', [
            'published' => true,
            'submitted_by' => 'stale-submitter',
            'submitted_at' => '2026-10-29 08:00:00.000000+00',
        ]);

        $this->insertSubmission(self::CATEGORY, 'categories', 'APPROVED', [
            'reviewed_by' => 'reviewer-category',
            'reviewed_at' => '2026-10-29 09:30:00.000000+00',
        ]);
        CatalogRow::category($this->connection(), self::CATEGORY, 'PENDING_APPROVAL', [
            'hidden' => true,
            'submitted_by' => 'stale-submitter',
        ]);

        $this->insertSubmission(self::MISSING, 'products', 'APPROVED', [
            'reviewed_by' => 'reviewer-missing',
        ]);

        $pending = $this->countPending();
        $missingView = $this->countMissingView();

        $report = $this->replayer()->replay(false);

        self::assertSame($pending, $report['written'], 'only rows whose six columns differ are written');
        self::assertSame($missingView, $report['missingView'] ?? 0);
        self::assertGreaterThanOrEqual(1, $report['missingView'] ?? 0, 'a submission without a view row is missing-view, not an insert');

        $product = $this->productRow(self::PRODUCT);
        self::assertSame('REJECTED', $product['approval_status']);
        self::assertSame('admin-submitter', $product['submitted_by']);
        self::assertSame('reviewer-product', $product['reviewed_by']);
        self::assertSame('Blurry photos.', $product['rejection_reason']);
        self::assertTrue((bool) $product['published'], 'a replay never touches publication');

        $category = $this->categoryRow(self::CATEGORY);
        self::assertSame('APPROVED', $category['approval_status']);
        self::assertSame('reviewer-category', $category['reviewed_by']);
        self::assertTrue((bool) $category['hidden'], 'a replay never touches hiding');

        self::assertFalse($this->viewExists(self::MISSING), 'a replay never inserts a missing view row');

        self::assertCount(0, $this->outboxRows(), 'a replay is not a command and emits no domain event');
        self::assertSame(0, $this->replayClaims(), 'a replay writes no processed_events row');
    }

    public function testASecondReplayWritesNothing(): void
    {
        $this->insertSubmission(self::PRODUCT, 'products', 'APPROVED', [
            'reviewed_by' => 'reviewer-product',
            'reviewed_at' => '2026-10-29 09:00:00.000000+00',
        ]);
        CatalogRow::product($this->connection(), self::PRODUCT, 'PENDING_APPROVAL');

        $first = $this->replayer()->replay(false);
        self::assertGreaterThanOrEqual(1, $first['written']);

        $second = $this->replayer()->replay(false);

        self::assertSame(0, $second['written'], 'R31: a second replay changes nothing');
        self::assertSame('APPROVED', $this->productRow(self::PRODUCT)['approval_status']);
    }

    public function testDryRunReportsTheWorkWithoutWriting(): void
    {
        $this->insertSubmission(self::PRODUCT, 'products', 'APPROVED', [
            'reviewed_by' => 'reviewer-product',
        ]);
        CatalogRow::product($this->connection(), self::PRODUCT, 'PENDING_APPROVAL', [
            'reviewed_by' => 'stale-reviewer',
        ]);

        $pending = $this->countPending();
        $report = $this->replayer()->replay(true);

        self::assertSame($pending, $report['written'], 'the dry run reports the rows it would write');
        self::assertSame('PENDING_APPROVAL', $this->productRow(self::PRODUCT)['approval_status'], '--dry-run must not write');
        self::assertSame('stale-reviewer', $this->productRow(self::PRODUCT)['reviewed_by']);
    }

    public function testOrphansAndMissingViewsAreReportedInBothDirectionsWithoutChangingRows(): void
    {
        CatalogRow::product($this->connection(), self::ORPHAN_PRODUCT, 'APPROVED');
        CatalogRow::category($this->connection(), self::ORPHAN_CATEGORY, 'REJECTED');
        $this->insertSubmission(self::MISSING, 'categories', 'APPROVED', [
            'reviewed_by' => 'reviewer-missing',
        ]);

        $orphans = $this->countOrphans();
        $missingView = $this->countMissingView();

        $report = $this->replayer()->replay(false);

        self::assertSame($orphans, $report['orphans']);
        self::assertGreaterThanOrEqual(2, $report['orphans'], 'both view tables can hold a row whose submission is gone');
        self::assertSame($missingView, $report['missingView'] ?? 0);
        self::assertGreaterThanOrEqual(1, $report['missingView'] ?? 0, 'both write directions are visible to the operator');
        self::assertTrue($this->viewExists(self::ORPHAN_PRODUCT), 'orphans are reported, never deleted');
        self::assertTrue($this->viewExists(self::ORPHAN_CATEGORY));
        self::assertFalse($this->viewExists(self::MISSING), 'a missing view row is reported, never inserted');
    }

    public function testTheCommandPrintsTheMissingViewCountButStillAcceptsTwoKeyReports(): void
    {
        $withMissingView = new class implements ProjectionReplayer {
            public function name(): string
            {
                return 'fake-view';
            }

            public function source(): string
            {
                return 'fake_source';
            }

            public function replay(bool $dryRun): array
            {
                return ['written' => 0, 'orphans' => 0, 'missingView' => 2];
            }
        };

        $tester = new CommandTester(new ProjectionReplayCommand(new ProjectionRegistry([$withMissingView])));
        $tester->execute(['name' => 'fake-view']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('2 missing view(s)', $tester->getDisplay(), 'R32: the operator sees submissions without a view row');

        $twoKeyReport = new class implements ProjectionReplayer {
            public function name(): string
            {
                return 'legacy-view';
            }

            public function source(): string
            {
                return 'legacy_source';
            }

            public function replay(bool $dryRun): array
            {
                return ['written' => 3, 'orphans' => 1];
            }
        };

        $legacy = new CommandTester(new ProjectionReplayCommand(new ProjectionRegistry([$twoKeyReport])));
        $legacy->execute(['name' => 'legacy-view', '--dry-run' => true]);

        self::assertSame(0, $legacy->getStatusCode(), 'a replayer that reports only written/orphans must keep working');
        self::assertStringContainsString('3 row(s)', $legacy->getDisplay());
        self::assertStringNotContainsString('missing view', $legacy->getDisplay());
    }

    /** @param array<string, mixed> $overrides */
    private function insertSubmission(string $slug, string $kind, string $status, array $overrides = []): void
    {
        $this->connection()->insert(
            'catalog_submission',
            array_replace([
                'kind' => $kind,
                'slug' => $slug,
                'approval_status' => $status,
                'submitted_by' => 'admin-submitter',
                'submitted_at' => '2026-10-29 08:00:00.000000+00',
                'reviewed_by' => null,
                'reviewed_at' => null,
                'rejection_reason' => null,
                'updated_at' => '2026-10-29 10:00:00.000000+00',
            ], $overrides),
        );
    }

    private function countPending(): int
    {
        return (int) $this->connection()->fetchOne(
            "SELECT count(*)
               FROM catalog_submission s
               JOIN storefront_product_view v ON s.kind = 'products' AND v.slug = s.slug
              WHERE v.approval_status IS DISTINCT FROM s.approval_status
                 OR v.submitted_by IS DISTINCT FROM s.submitted_by
                 OR v.submitted_at IS DISTINCT FROM s.submitted_at
                 OR v.reviewed_by IS DISTINCT FROM s.reviewed_by
                 OR v.reviewed_at IS DISTINCT FROM s.reviewed_at
                 OR v.rejection_reason IS DISTINCT FROM s.rejection_reason",
        ) + (int) $this->connection()->fetchOne(
            "SELECT count(*)
               FROM catalog_submission s
               JOIN storefront_category v ON s.kind = 'categories' AND v.slug = s.slug
              WHERE v.approval_status IS DISTINCT FROM s.approval_status
                 OR v.submitted_by IS DISTINCT FROM s.submitted_by
                 OR v.submitted_at IS DISTINCT FROM s.submitted_at
                 OR v.reviewed_by IS DISTINCT FROM s.reviewed_by
                 OR v.reviewed_at IS DISTINCT FROM s.reviewed_at
                 OR v.rejection_reason IS DISTINCT FROM s.rejection_reason",
        );
    }

    private function countOrphans(): int
    {
        return (int) $this->connection()->fetchOne(
            "SELECT count(*) FROM storefront_product_view v
               LEFT JOIN catalog_submission s ON s.kind = 'products' AND s.slug = v.slug
              WHERE s.slug IS NULL",
        ) + (int) $this->connection()->fetchOne(
            "SELECT count(*) FROM storefront_category v
               LEFT JOIN catalog_submission s ON s.kind = 'categories' AND s.slug = v.slug
              WHERE s.slug IS NULL",
        );
    }

    private function countMissingView(): int
    {
        return (int) $this->connection()->fetchOne(
            "SELECT count(*) FROM catalog_submission s
               LEFT JOIN storefront_product_view v ON s.kind = 'products' AND v.slug = s.slug
              WHERE s.kind = 'products' AND v.slug IS NULL",
        ) + (int) $this->connection()->fetchOne(
            "SELECT count(*) FROM catalog_submission s
               LEFT JOIN storefront_category v ON s.kind = 'categories' AND v.slug = s.slug
              WHERE s.kind = 'categories' AND v.slug IS NULL",
        );
    }

    /** @return array<string, mixed> */
    private function productRow(string $slug): array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT approval_status, submitted_by, submitted_at, reviewed_by, reviewed_at,
                    rejection_reason, published
               FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $slug],
        );
        self::assertIsArray($row);

        return $row;
    }

    /** @return array<string, mixed> */
    private function categoryRow(string $slug): array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT approval_status, submitted_by, submitted_at, reviewed_by, reviewed_at,
                    rejection_reason, hidden
               FROM storefront_category WHERE slug = :slug',
            ['slug' => $slug],
        );
        self::assertIsArray($row);

        return $row;
    }

    private function viewExists(string $slug): bool
    {
        return (int) $this->connection()->fetchOne(
            'SELECT count(*) FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $slug],
        ) + (int) $this->connection()->fetchOne(
            'SELECT count(*) FROM storefront_category WHERE slug = :slug',
            ['slug' => $slug],
        ) > 0;
    }

    /** @return list<array<string, mixed>> */
    private function outboxRows(): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT aggregate_id FROM outbox WHERE aggregate_id LIKE :prefix',
            ['prefix' => '%-submission-%'],
        );
    }

    private function replayClaims(): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT count(*) FROM processed_events WHERE consumer = :consumer',
            ['consumer' => ReplayCatalogSubmission::class],
        );
    }

    private function removeRows(): void
    {
        $connection = $this->connection();

        foreach (self::SLUGS as $slug) {
            $connection->executeStatement(
                'DELETE FROM catalog_submission WHERE slug = :slug',
                ['slug' => $slug],
            );
        }

        $connection->executeStatement(
            'DELETE FROM outbox WHERE aggregate_id LIKE :prefix',
            ['prefix' => '%-submission-%'],
        );

        CatalogRow::deleteProducts($connection, ...self::SLUGS);
        CatalogRow::deleteCategories($connection, ...self::SLUGS);
    }

    private function connection(): Connection
    {
        $connection = $this->connection;
        if (!$connection instanceof Connection) {
            throw new \LogicException('The DBAL connection was not bootstrapped by setUp().');
        }

        return $connection;
    }

    private function replayer(): ReplayCatalogSubmission
    {
        $replayer = $this->replayer;
        if (!$replayer instanceof ReplayCatalogSubmission) {
            throw new \LogicException('The replayer was not resolved from the container by setUp().');
        }

        return $replayer;
    }
}
