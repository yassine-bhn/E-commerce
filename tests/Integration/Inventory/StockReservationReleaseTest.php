<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Shared\Application\Port\StockReservationPort;
use App\Shared\Domain\Inventory\InsufficientStockException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * spec-run-15 WP4 (R15/R16/R17) — `inventory_stock` is the authoritative balance and the view's
 * `stock_quantity` is only its display cache.
 *
 * The reservation must move `reserved` with one conditional UPDATE, never read-then-write the view,
 * and the cache must equal `on_hand - reserved` in the same transaction. A tracked product whose
 * balance row is missing fails closed instead of silently reserving from the view, and an untracked
 * product (null `stock_quantity`) keeps the legacy reservation-free behaviour.
 */
final class StockReservationReleaseTest extends KernelTestCase
{
    private ?string $slug = null;
    private ?string $orderId = null;

    protected function tearDown(): void
    {
        if (null === $this->slug) {
            parent::tearDown();

            return;
        }

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        if (null !== $this->orderId) {
            $connection->executeStatement('DELETE FROM inventory_reservation WHERE order_id = :id', ['id' => $this->orderId]);
        }
        $connection->executeStatement('DELETE FROM inventory_stock WHERE product_slug = :slug', ['slug' => $this->slug]);
        $connection->executeStatement('DELETE FROM storefront_product_view WHERE slug = :slug', ['slug' => $this->slug]);

        parent::tearDown();
    }

    public function testReservingDecrementsTheBalanceAndRefreshesTheCache(): void
    {
        $connection = $this->seedProduct(5);
        $this->orderId = Uuid::v7()->toRfc4122();

        $this->stock($connection)->reserve($this->orderId, [
            ['slug' => $this->slug(), 'quantity' => 2],
        ]);

        self::assertSame(['on_hand' => 5, 'reserved' => 2], $this->balance($connection));
        self::assertSame(3, $this->cache($connection), 'the cache is on_hand - reserved');
    }

    public function testReservingTheSameOrderTwiceReservesOnce(): void
    {
        $connection = $this->seedProduct(5);
        $this->orderId = Uuid::v7()->toRfc4122();
        $stock = $this->stock($connection);

        $stock->reserve($this->orderId, [['slug' => $this->slug(), 'quantity' => 2]]);
        $stock->reserve($this->orderId, [['slug' => $this->slug(), 'quantity' => 2]]);

        self::assertSame(['on_hand' => 5, 'reserved' => 2], $this->balance($connection));
        self::assertSame(3, $this->cache($connection));
    }

    public function testATrackedProductWithoutABalanceRowFailsClosed(): void
    {
        $connection = $this->seedProduct(5, withBalance: false);
        $this->orderId = Uuid::v7()->toRfc4122();

        try {
            $this->stock($connection)->reserve($this->orderId, [
                ['slug' => $this->slug(), 'quantity' => 1],
            ]);
            self::fail('a tracked product with no inventory_stock row must not fall back to the view');
        } catch (InsufficientStockException $e) {
            self::assertSame($this->slug, $e->productSlug);
        }

        self::assertSame(5, $this->cache($connection), 'the view is not the authority and stays untouched');
    }

    public function testAnUntrackedProductKeepsTheLegacyReservationFreeBehaviour(): void
    {
        $connection = $this->seedProduct(null, withBalance: false);
        $this->orderId = Uuid::v7()->toRfc4122();

        $this->stock($connection)->reserve($this->orderId, [
            ['slug' => $this->slug(), 'quantity' => 2],
        ]);

        self::assertNull($this->cache($connection), 'an untracked product stays untracked');
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM inventory_reservation WHERE order_id = :id',
            ['id' => $this->orderId],
        ), 'no stock ledger row is kept for an untracked product');
        self::assertFalse($connection->fetchOne(
            'SELECT 1 FROM inventory_stock WHERE product_slug = :slug',
            ['slug' => $this->slug],
        ), 'no balance row is invented for an untracked product');
    }

    public function testReleasingTwiceRestoresTheReservedQuantityOnce(): void
    {
        $connection = $this->seedProduct(5);
        $this->orderId = Uuid::v7()->toRfc4122();
        $stock = $this->stock($connection);

        $stock->reserve($this->orderId, [['slug' => $this->slug(), 'quantity' => 2]]);

        $stock->release($this->orderId);
        self::assertSame(['on_hand' => 5, 'reserved' => 0], $this->balance($connection), 'the first release gives the units back');
        self::assertSame(5, $this->cache($connection));

        $stock->release($this->orderId);
        self::assertSame(['on_hand' => 5, 'reserved' => 0], $this->balance($connection), 'the second release must not release again');
        self::assertSame(5, $this->cache($connection));

        self::assertSame('released', $connection->fetchOne(
            'SELECT status FROM inventory_reservation WHERE order_id = :id',
            ['id' => $this->orderId],
        ));
    }

    private function slug(): string
    {
        if (null === $this->slug) {
            self::fail('seedProduct() must run before the slug is used');
        }

        return $this->slug;
    }

    private function stock(Connection $connection): StockReservationPort
    {
        /** @var StockReservationPort $stock */
        $stock = static::getContainer()->get(StockReservationPort::class);

        return $stock;
    }

    private function seedProduct(?int $stock, bool $withBalance = true): Connection
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $this->slug = 'test-release-idem-'.substr(Uuid::v4()->toRfc4122(), 0, 8);
        $connection->executeStatement(
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

        if ($withBalance) {
            self::assertNotNull($stock);
            $connection->executeStatement(
                'INSERT INTO inventory_stock (product_slug, on_hand) VALUES (:slug, :on_hand)',
                ['slug' => $this->slug, 'on_hand' => $stock],
            );
        }

        return $connection;
    }

    /** @return array{on_hand: int, reserved: int} */
    private function balance(Connection $connection): array
    {
        /** @var array{on_hand: int, reserved: int} $row */
        $row = $connection->fetchAssociative(
            'SELECT on_hand, reserved FROM inventory_stock WHERE product_slug = :slug',
            ['slug' => $this->slug],
        );

        return ['on_hand' => (int) $row['on_hand'], 'reserved' => (int) $row['reserved']];
    }

    private function cache(Connection $connection): ?int
    {
        $value = $connection->fetchOne(
            'SELECT stock_quantity FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $this->slug],
        );

        return false === $value || null === $value ? null : (int) $value;
    }
}
