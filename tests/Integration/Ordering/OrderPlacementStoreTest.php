<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

use App\Ordering\Application\Port\OrderPlacementStore;
use App\Ordering\Domain\Model\Order;
use App\Ordering\Domain\Model\OrderLine;
use App\Ordering\Infrastructure\Persistence\DbalOrderPlacementStore;
use App\Shared\Domain\Money;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * solid Task 2.7 — the placement adapter against PostgreSQL: the idempotency claim is
 * first-write-wins, and save() delegates to the order repository so the aggregate keeps its
 * persistence owner.
 */
final class OrderPlacementStoreTest extends KernelTestCase
{
    /** @var list<string> */
    private array $claimedKeys = [];

    /** @var list<string> */
    private array $createdOrderIds = [];

    protected function tearDown(): void
    {
        $connection = self::connection();

        if ([] !== $this->createdOrderIds) {
            $connection->executeStatement(
                'DELETE FROM ordering_order_lines WHERE order_id IN (:ids)',
                ['ids' => $this->createdOrderIds],
                ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
            $connection->executeStatement(
                'DELETE FROM ordering_orders WHERE id IN (:ids)',
                ['ids' => $this->createdOrderIds],
                ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }

        if ([] !== $this->claimedKeys) {
            $connection->executeStatement(
                'DELETE FROM processed_commands WHERE key IN (:keys)',
                ['keys' => $this->claimedKeys],
                ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }

        $this->createdOrderIds = [];
        $this->claimedKeys = [];

        parent::tearDown();
    }

    public function testClaimInsertsTheKeyOnceAndRejectsTheReplay(): void
    {
        $store = $this->store();
        $key = 'order-key-'.Uuid::v7()->toRfc4122();
        $this->claimedKeys[] = $key;

        self::assertTrue($store->claim($key), 'the first claim wins');
        self::assertFalse($store->claim($key), 'a replayed key must be refused');
    }

    public function testSaveDelegatesToTheOrderRepository(): void
    {
        $store = $this->store();
        $orderId = Uuid::v7()->toRfc4122();
        $this->createdOrderIds[] = $orderId;
        $order = Order::placeOnline(
            new \App\Ordering\Domain\Model\OrderId($orderId),
            Uuid::v7()->toRfc4122(),
            'ZG-SOLID-T2.7-'.substr($orderId, 0, 6),
            Money::ofMinor(12900, \App\Shared\Domain\Currency::TND),
            Money::ofMinor(0, \App\Shared\Domain\Currency::TND),
            Money::ofMinor(700, \App\Shared\Domain\Currency::TND),
            Money::ofMinor(13600, \App\Shared\Domain\Currency::TND),
            'home',
            'cod',
            null,
            [
                OrderLine::snapshot(
                    Uuid::v7()->toRfc4122(),
                    'fauteuil',
                    'Fauteuil',
                    null,
                    Money::ofMinor(12900, \App\Shared\Domain\Currency::TND),
                    1,
                ),
            ],
            new \DateTimeImmutable(),
        );

        $store->save($order);

        $row = self::connection()->fetchAssociative(
            'SELECT order_number, total_minor FROM ordering_orders WHERE id = :id',
            ['id' => $orderId],
        );
        self::assertNotFalse($row, 'the order row is written through the repository');
        self::assertSame('ZG-SOLID-T2.7-'.substr($orderId, 0, 6), (string) $row['order_number']);
        self::assertSame(13600, (int) $row['total_minor']);
    }

    private function store(): DbalOrderPlacementStore
    {
        self::bootKernel();

        $store = static::getContainer()->get(OrderPlacementStore::class);
        \assert($store instanceof DbalOrderPlacementStore);

        return $store;
    }

    private static function connection(): Connection
    {
        self::bootKernel();

        $connection = static::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        return $connection;
    }
}
