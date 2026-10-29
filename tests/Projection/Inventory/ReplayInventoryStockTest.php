<?php

declare(strict_types=1);

namespace App\Tests\Projection\Inventory;

use App\Inventory\Infrastructure\Projection\ReplayInventoryStock;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * spec-run-15 WP4 (R19/R31) — `inventory-stock` rebuilds the display cache from the balance.
 *
 * `inventory_stock` is the authority, so a replay copies `on_hand - reserved` into
 * `storefront_product_view.stock_quantity`, writes only real differences (a second run is zero),
 * never inserts a view row, never turns an untracked row into a tracked one, and reports in both
 * directions: a tracked view row whose balance is gone is an orphan, and a balance whose view row is
 * gone is a missing view.
 */
final class ReplayInventoryStockTest extends KernelTestCase
{
    private const string CORRUPTED = 'it-replay-stock-corrupted';
    private const string UNTRACKED = 'it-replay-stock-untracked';
    private const string ORPHAN = 'it-replay-stock-orphan';
    private const string MISSING_VIEW = 'it-replay-stock-missing-view';
    private const string MISSING_VIEW_2 = 'it-replay-stock-missing-view-2';

    private ?Connection $connection = null;
    private ?ReplayInventoryStock $replayer = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->connection = $connection;

        /** @var ReplayInventoryStock $replayer */
        $replayer = $container->get(ReplayInventoryStock::class);
        $this->replayer = $replayer;

        $this->removeRows();
    }

    protected function tearDown(): void
    {
        $this->removeRows();

        parent::tearDown();
    }

    public function testItRestoresACorruptedCacheAndASecondReplayWritesNothing(): void
    {
        $this->seedProduct(self::CORRUPTED, 0);
        $this->seedBalance(self::CORRUPTED, 5, 2);

        $first = $this->replayer()->replay(false);

        self::assertGreaterThanOrEqual(1, $first['written']);
        self::assertSame(3, $this->cache(self::CORRUPTED), 'the cache is rebuilt as on_hand - reserved');

        $second = $this->replayer()->replay(false);

        self::assertSame(0, $second['written'], 'R19: a second replay changes nothing');
        self::assertSame(3, $this->cache(self::CORRUPTED));
    }

    public function testADryRunReportsTheWorkWithoutWriting(): void
    {
        $this->seedProduct(self::CORRUPTED, 0);
        $this->seedBalance(self::CORRUPTED, 5, 2);

        $report = $this->replayer()->replay(true);

        self::assertGreaterThanOrEqual(1, $report['written']);
        self::assertSame(0, $this->cache(self::CORRUPTED), '--dry-run writes nothing');
    }

    public function testItLeavesAnUntrackedRowAlone(): void
    {
        $this->seedProduct(self::UNTRACKED, null);
        $this->seedBalance(self::UNTRACKED, 5, 1);
        $pending = $this->countPending();

        $report = $this->replayer()->replay(false);

        self::assertNull($this->cache(self::UNTRACKED), 'stock_quantity IS NULL stays untracked');
        self::assertSame($pending, $report['written'], 'an untracked row is not counted as written');
    }

    public function testItReportsBothDirectionsWithoutChangingRows(): void
    {
        $this->seedBalance(self::MISSING_VIEW, 4, 0);
        $this->seedBalance(self::MISSING_VIEW_2, 6, 1);
        $this->seedProduct(self::ORPHAN, 2);

        $orphans = $this->countOrphans();
        $missingView = $this->countMissingView();
        $report = $this->replayer()->replay(false);

        self::assertSame($orphans, $report['orphans']);
        self::assertSame($missingView, $report['missingView'] ?? 0);
        self::assertGreaterThanOrEqual(1, $report['orphans'], 'a tracked view row with no balance is an orphan');
        self::assertGreaterThanOrEqual(2, $report['missingView'] ?? 0, 'a balance whose view row is gone is missing-view');
        self::assertFalse($this->viewExists(self::MISSING_VIEW), 'a replay never inserts a view row for a missing view');
        self::assertSame(0, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM inventory_stock WHERE product_slug = :slug',
            ['slug' => self::ORPHAN],
        ), 'a replay never inserts a balance row');
    }

    private function seedProduct(string $slug, ?int $stock): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO storefront_product_view (
                id, slug, name, brand, ref, category_slug, subcategory, price_minor, old_price_minor, currency,
                stock, stock_quantity, material, dims, image_path, rating, reviews, is_new, is_best, headrest, description, variants, approval_status, published
            )
            SELECT :id, :slug, name, brand, ref, category_slug, subcategory, price_minor, old_price_minor, currency,
                   stock, :stock_quantity, material, dims, image_path, rating, reviews, is_new, is_best, headrest, description, variants, approval_status, published
            FROM storefront_product_view
            WHERE slug = :source',
            [
                'id' => Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_URL), 'it:stock:'.$slug)->toRfc4122(),
                'slug' => $slug,
                'stock_quantity' => $stock,
                'source' => 'baignoire-d-code',
            ],
            ['stock_quantity' => ParameterType::INTEGER],
        );
    }

    private function seedBalance(string $slug, int $onHand, int $reserved): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO inventory_stock (product_slug, on_hand, reserved) VALUES (:slug, :on_hand, :reserved)',
            ['slug' => $slug, 'on_hand' => $onHand, 'reserved' => $reserved],
        );
    }

    private function cache(string $slug): ?int
    {
        $value = $this->connection()->fetchOne(
            'SELECT stock_quantity FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $slug],
        );

        return false === $value || null === $value ? null : (int) $value;
    }

    private function viewExists(string $slug): bool
    {
        return false !== $this->connection()->fetchOne(
            'SELECT 1 FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $slug],
        );
    }

    private function countPending(): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT COUNT(*)
               FROM storefront_product_view v
               JOIN inventory_stock s ON s.product_slug = v.slug
              WHERE v.stock_quantity IS NOT NULL
                AND v.stock_quantity IS DISTINCT FROM s.on_hand - s.reserved',
        );
    }

    private function countOrphans(): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT COUNT(*)
               FROM storefront_product_view v
               LEFT JOIN inventory_stock s ON s.product_slug = v.slug
              WHERE v.stock_quantity IS NOT NULL AND s.product_slug IS NULL',
        );
    }

    private function countMissingView(): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT COUNT(*)
               FROM inventory_stock s
               LEFT JOIN storefront_product_view v ON v.slug = s.product_slug
              WHERE v.slug IS NULL',
        );
    }

    private function removeRows(): void
    {
        if (null === $this->connection) {
            return;
        }

        $connection = $this->connection;
        $slugs = [self::CORRUPTED, self::UNTRACKED, self::ORPHAN, self::MISSING_VIEW, self::MISSING_VIEW_2];

        $connection->executeStatement(
            'DELETE FROM inventory_stock WHERE product_slug IN (:slugs)',
            ['slugs' => $slugs],
            ['slugs' => ArrayParameterType::STRING],
        );
        $connection->executeStatement(
            'DELETE FROM storefront_product_view WHERE slug IN (:slugs)',
            ['slugs' => $slugs],
            ['slugs' => ArrayParameterType::STRING],
        );
    }

    private function connection(): Connection
    {
        if (null === $this->connection) {
            throw new \LogicException('The DBAL connection was not bootstrapped by setUp().');
        }

        return $this->connection;
    }

    private function replayer(): ReplayInventoryStock
    {
        if (null === $this->replayer) {
            throw new \LogicException('The replayer was not resolved from the container by setUp().');
        }

        return $this->replayer;
    }
}
