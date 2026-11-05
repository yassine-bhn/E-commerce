<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Inventory\Infrastructure\Console\BackfillInventoryStockCommand;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * spec-run-15 WP4 (R18) — `app:inventory:backfill-stock` seeds the missing authoritative balances.
 *
 * The view remains the display cache, so the operator step must preserve the quantity that is
 * available today: `on_hand = stock_quantity + active reservations` and `reserved` is the sum of
 * the order lines still held. It is dry-run by default, writes only with `--apply`, reports rows it
 * cannot initialise safely, and a second run writes nothing.
 */
final class BackfillInventoryStockCommandTest extends KernelTestCase
{
    private ?Connection $connection = null;
    private ?string $slug = null;
    private ?string $orderId = null;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $this->connection = $connection;

        $this->removeRows();
    }

    protected function tearDown(): void
    {
        $this->removeRows();

        parent::tearDown();
    }

    public function testADryRunReportsTheRowsWithoutWriting(): void
    {
        $this->seedProduct(3);
        $this->seedReservation(2);

        $tester = $this->command();
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('would be created', $tester->getDisplay());
        self::assertFalse($this->balanceRow(), '--dry-run writes nothing');
    }

    public function testApplyPreservesTheCurrentlyAvailableQuantity(): void
    {
        $this->seedProduct(3);
        $this->seedReservation(2);

        $tester = $this->command();
        $tester->execute(['--apply' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(
            ['on_hand' => 5, 'reserved' => 2, 'available' => 3],
            $this->balance(),
            'stock 3 with an active reservation of 2 means 5 on hand and 3 still sellable',
        );
        self::assertSame(3, $this->cache(), 'the display cache keeps the available quantity');
    }

    public function testASecondApplyWritesNothing(): void
    {
        $this->seedProduct(4);

        $this->command()->execute(['--apply' => true]);
        $tester = $this->command();
        $tester->execute(['--apply' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Created 0', $tester->getDisplay(), 'R18: a second run is idempotent');
        self::assertSame(['on_hand' => 4, 'reserved' => 0, 'available' => 4], $this->balance());
    }

    public function testItReportsATrackedRowItCannotInitialiseSafely(): void
    {
        $this->seedProduct(2_147_483_647);
        $this->seedReservation(1);

        $tester = $this->command();
        $tester->execute(['--apply' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 tracked row(s) cannot be initialised safely', $tester->getDisplay());
        self::assertFalse($this->balanceRow(), 'an on_hand that overflows the INT column must not be inserted');
    }

    public function testAnUntrackedRowIsSkippedEntirely(): void
    {
        $this->seedProduct(null);

        $tester = $this->command();
        $tester->execute(['--apply' => true]);

        self::assertStringContainsString('Created 0', $tester->getDisplay());
        self::assertFalse($this->balanceRow(), 'a product with stock_quantity IS NULL stays untracked');
    }

    private function command(): CommandTester
    {
        return new CommandTester(new BackfillInventoryStockCommand($this->connection()));
    }

    private function seedProduct(?int $stock): void
    {
        $this->slug = 'test-backfill-stock-'.substr(Uuid::v4()->toRfc4122(), 0, 8);
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
                'id' => Uuid::v7()->toRfc4122(),
                'slug' => $this->slug,
                'stock_quantity' => $stock,
                'source' => 'baignoire-d-code',
            ],
            ['stock_quantity' => ParameterType::INTEGER],
        );
    }

    private function seedReservation(int $quantity): void
    {
        $this->orderId = Uuid::v7()->toRfc4122();
        $this->connection()->executeStatement(
            'INSERT INTO inventory_reservation (order_id, product_slug, quantity) VALUES (:order_id, :slug, :quantity)',
            ['order_id' => $this->orderId, 'slug' => $this->slug, 'quantity' => $quantity],
        );
    }

    private function balanceRow(): bool
    {
        return false !== $this->connection()->fetchOne(
            'SELECT 1 FROM inventory_stock WHERE product_slug = :slug',
            ['slug' => $this->slug],
        );
    }

    /** @return array{on_hand: int, reserved: int, available: int} */
    private function balance(): array
    {
        /** @var array{on_hand: int, reserved: int}|false $row */
        $row = $this->connection()->fetchAssociative(
            'SELECT on_hand, reserved FROM inventory_stock WHERE product_slug = :slug',
            ['slug' => $this->slug],
        );
        self::assertIsArray($row);

        return [
            'on_hand' => (int) $row['on_hand'],
            'reserved' => (int) $row['reserved'],
            'available' => (int) $row['on_hand'] - (int) $row['reserved'],
        ];
    }

    private function cache(): ?int
    {
        $value = $this->connection()->fetchOne(
            'SELECT stock_quantity FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $this->slug],
        );

        return false === $value || null === $value ? null : (int) $value;
    }

    private function removeRows(): void
    {
        if (null === $this->connection || null === $this->slug) {
            return;
        }

        if (null !== $this->orderId) {
            $this->connection->executeStatement('DELETE FROM inventory_reservation WHERE order_id = :id', ['id' => $this->orderId]);
        }
        $this->connection->executeStatement('DELETE FROM inventory_stock WHERE product_slug = :slug', ['slug' => $this->slug]);
        $this->connection->executeStatement('DELETE FROM storefront_product_view WHERE slug = :slug', ['slug' => $this->slug]);
        $this->slug = null;
        $this->orderId = null;
    }

    private function connection(): Connection
    {
        if (null === $this->connection) {
            throw new \LogicException('The DBAL connection was not bootstrapped by setUp().');
        }

        return $this->connection;
    }
}
