<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Inventory\Infrastructure\Persistence\DbalStockReservationAdapter;
use App\Shared\Domain\Inventory\InsufficientStockException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * spec-run-15 WP4 (R15) — twenty contenders, five units, no oversell.
 *
 * No `pcntl` in this container, so each contender is a real second backend: its own DBAL connection,
 * its own transaction and its own row lock. The first winner holds the balance row; the other
 * nineteen hit the server's `lock_timeout` (SQLSTATE 55P03) and roll back. After the winner commits,
 * the same nineteen retry against what is left: exactly four more succeed, fifteen are refused, and
 * the balance, the cache and every active reservation agree.
 */
final class StockReservationConcurrencyTest extends KernelTestCase
{
    private const int CONTENDERS = 20;
    private const int UNITS = 5;

    public function testTwentyContendersNeverOversellFiveUnits(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);

        try {
            $connection->executeQuery('SELECT 1 FROM inventory_stock LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('PostgreSQL schema not migrated (run doctrine:migrations:migrate).');
        }

        $slug = 'test-stock-race-'.substr(Uuid::v4()->toRfc4122(), 0, 8);
        $this->seed($connection, $slug);

        /** @var list<Connection> $contenders */
        $contenders = [];
        for ($i = 0; $i < self::CONTENDERS; ++$i) {
            $contenders[] = $this->newConnection($connection);
        }

        $winner = array_shift($contenders);
        if (null === $winner) {
            self::fail('at least one contender connection is required');
        }

        try {
            $winner->beginTransaction();
            new DbalStockReservationAdapter($winner)->reserve(
                Uuid::v7()->toRfc4122(),
                [['slug' => $slug, 'quantity' => 1]],
            );

            $timeouts = 0;
            foreach ($contenders as $rival) {
                $rival->executeStatement("SET lock_timeout = '250ms'");
                $rival->beginTransaction();
                try {
                    new DbalStockReservationAdapter($rival)->reserve(
                        Uuid::v7()->toRfc4122(),
                        [['slug' => $slug, 'quantity' => 1]],
                    );
                    self::fail('a contender must not proceed while the winning balance row is locked');
                } catch (DriverException $e) {
                    self::assertSame('55P03', $e->getSQLState(), $e->getMessage());
                    ++$timeouts;
                } finally {
                    if ($rival->isTransactionActive()) {
                        $rival->rollBack();
                    }
                }
            }
            self::assertSame(self::CONTENDERS - 1, $timeouts, 'nineteen contenders time out on the locked row');

            $winner->commit();

            $accepted = 0;
            $refused = 0;
            foreach ($contenders as $rival) {
                $rival->beginTransaction();
                try {
                    new DbalStockReservationAdapter($rival)->reserve(
                        Uuid::v7()->toRfc4122(),
                        [['slug' => $slug, 'quantity' => 1]],
                    );
                    $rival->commit();
                    ++$accepted;
                } catch (InsufficientStockException) {
                    $rival->rollBack();
                    ++$refused;
                }
            }

            self::assertSame(self::UNITS - 1, $accepted, 'four retries take the units the winner left');
            self::assertSame(self::CONTENDERS - self::UNITS, $refused, 'fifteen retries are refused');

            self::assertSame(self::UNITS, (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM inventory_reservation WHERE product_slug = :slug AND status = :status',
                ['slug' => $slug, 'status' => 'reserved'],
            ), 'exactly five reservations survive the contention');
            self::assertSame(0, (int) $connection->fetchOne(
                'SELECT stock_quantity FROM storefront_product_view WHERE slug = :slug',
                ['slug' => $slug],
            ), 'the cache equals available stock');
            self::assertSame(
                ['on_hand' => self::UNITS, 'reserved' => self::UNITS],
                $this->balance($connection, $slug),
            );
            self::assertSame(0, (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM inventory_stock WHERE on_hand < 0 OR reserved < 0 OR reserved > on_hand',
            ), 'no value is negative and no reservation exceeds on_hand');
        } finally {
            foreach ($contenders as $rival) {
                $rival->close();
            }
            $connection->executeStatement('DELETE FROM inventory_reservation WHERE product_slug = :slug', ['slug' => $slug]);
            $connection->executeStatement('DELETE FROM inventory_stock WHERE product_slug = :slug', ['slug' => $slug]);
            $connection->executeStatement('DELETE FROM storefront_product_view WHERE slug = :slug', ['slug' => $slug]);
        }
    }

    /**
     * A second DBAL connection is a second backend: same database, own transaction and row locks.
     */
    private function newConnection(Connection $connection): Connection
    {
        // The same database over a second backend: same params, own transaction and row locks.
        $params = $connection->getParams();
        $params['dbname'] = (string) $connection->fetchOne('SELECT current_database()');

        return new Connection($params, $connection->getDriver());
    }

    private function seed(Connection $connection, string $slug): void
    {
        $connection->executeStatement(
            'INSERT INTO storefront_product_view (
                id, slug, name, brand, ref, category_slug, subcategory, price_minor, old_price_minor, currency,
                stock, stock_quantity, material, dims, image_path, rating, reviews, is_new, is_best, headrest, description, variants, approval_status, published
            )
            SELECT :id, :slug, name, brand, ref, category_slug, subcategory, price_minor, old_price_minor, currency,
                   stock, :units, material, dims, image_path, rating, reviews, is_new, is_best, headrest, description, variants, approval_status, published
            FROM storefront_product_view
            WHERE slug = :source',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'slug' => $slug,
                'units' => self::UNITS,
                'source' => 'baignoire-d-code',
            ],
        );
        $connection->executeStatement(
            'INSERT INTO inventory_stock (product_slug, on_hand) VALUES (:slug, :units)',
            ['slug' => $slug, 'units' => self::UNITS],
        );
    }

    /** @return array{on_hand: int, reserved: int} */
    private function balance(Connection $connection, string $slug): array
    {
        /** @var array{on_hand: int, reserved: int} $row */
        $row = $connection->fetchAssociative(
            'SELECT on_hand, reserved FROM inventory_stock WHERE product_slug = :slug',
            ['slug' => $slug],
        );

        return ['on_hand' => $row['on_hand'], 'reserved' => $row['reserved']];
    }
}
