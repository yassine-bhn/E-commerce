<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Inventory\Infrastructure\Persistence\DbalStockReservationAdapter;
use App\Shared\Application\Port\StockReservationPort;
use App\Shared\Domain\Inventory\InsufficientStockException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class StockReservationConcurrencyTest extends KernelTestCase
{
    public function testLastUnitGoesToExactlyOneReservation(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);

        try {
            $connection->executeQuery('SELECT 1 FROM inventory_reservation LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('PostgreSQL schema not migrated (run doctrine:migrations:migrate).');
        }

        $slug = 'test-stock-race-'.substr(Uuid::v4()->toRfc4122(), 0, 8);
        $parentOrderId = Uuid::v7()->toRfc4122();
        $rivalOrderId = Uuid::v7()->toRfc4122();

        $this->seedOneUnit($connection, $slug);
        $rival = $this->rivalConnection($connection);

        /** @var StockReservationPort $stock */
        $stock = $container->get(StockReservationPort::class);
        $rivalStock = new DbalStockReservationAdapter($rival);

        try {
            $connection->beginTransaction();
            try {
                $stock->reserve($parentOrderId, [['slug' => $slug, 'quantity' => 1]]);
                self::assertSame(0, $this->stockOf($connection, $slug), 'the first reservation takes the last unit');

                // TDD-005 — the barrier is the server's own row lock, not a fixed sleep: while the
                // winning transaction is open the rival's decrement cannot complete, and the
                // server-side lock_timeout turns that wait into evidence instead of a race.
                $rival->executeStatement("SET lock_timeout = '250ms'");
                $rival->beginTransaction();
                try {
                    $rivalStock->reserve($rivalOrderId, [['slug' => $slug, 'quantity' => 1]]);
                    self::fail('the rival reservation must not proceed while the winning row is locked');
                } catch (DriverException $e) {
                    self::assertSame('55P03', $e->getSQLState(), $e->getMessage());
                } finally {
                    if ($rival->isTransactionActive()) {
                        $rival->rollBack();
                    }
                }

                $connection->commit();
            } catch (\Throwable $e) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                throw $e;
            }

            // The winner's decrement is committed: the same rival, with the lock gone, is refused
            // by the conditional UPDATE instead of reserving the unit twice.
            $rival->beginTransaction();
            try {
                $rivalStock->reserve($rivalOrderId, [['slug' => $slug, 'quantity' => 1]]);
                self::fail('the last unit was already reserved');
            } catch (InsufficientStockException) {
                $rival->rollBack();
            }

            self::assertSame(0, $this->stockOf($connection, $slug));

            $reservations = $connection->fetchAllAssociative(
                'SELECT order_id, status FROM inventory_reservation WHERE product_slug = :slug',
                ['slug' => $slug],
            );
            self::assertCount(1, $reservations, 'exactly one reservation survives the contention');
            self::assertSame($parentOrderId, $reservations[0]['order_id']);
            self::assertSame('reserved', $reservations[0]['status']);
        } finally {
            $rival->close();
            $connection->executeStatement('DELETE FROM inventory_reservation WHERE product_slug = :slug', ['slug' => $slug]);
            $connection->executeStatement('DELETE FROM storefront_product_view WHERE slug = :slug', ['slug' => $slug]);
        }
    }

    /**
     * A second DBAL connection is a second backend: same database, own transaction and row locks.
     */
    private function rivalConnection(Connection $connection): Connection
    {
        /** @var array<string, mixed> $params */
        $params = $connection->getParams();
        unset($params['url'], $params['dbname_suffix']);
        $params['dbname'] = (string) $connection->fetchOne('SELECT current_database()');

        return DriverManager::getConnection($params);
    }

    private function seedOneUnit(Connection $connection, string $slug): void
    {
        $connection->executeStatement(
            'INSERT INTO storefront_product_view (
                id, slug, name, brand, ref, category_slug, subcategory, price_minor, old_price_minor, currency,
                stock, stock_quantity, material, dims, image_path, rating, reviews, is_new, is_best, headrest, description, variants, approval_status, published
            )
            SELECT :id, :slug, name, brand, ref, category_slug, subcategory, price_minor, old_price_minor, currency,
                   stock, 1, material, dims, image_path, rating, reviews, is_new, is_best, headrest, description, variants, approval_status, published
            FROM storefront_product_view
            WHERE slug = :source',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'slug' => $slug,
                'source' => 'baignoire-d-code',
            ],
        );
    }

    private function stockOf(Connection $connection, string $slug): int
    {
        return (int) $connection->fetchOne(
            'SELECT stock_quantity FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $slug],
        );
    }
}
