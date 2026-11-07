<?php

declare(strict_types=1);

namespace App\Tests\Integration\Contract;

use App\Ordering\Domain\Model\Order;
use App\Ordering\Domain\Model\OrderId;
use App\Ordering\Domain\Model\OrderLine;
use App\Ordering\Domain\Repository\OrderRepository;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * LSP-001 — the contract every OrderRepository adapter must honour (solid Task 5.1):
 * save then get returns the same aggregate, a missing id throws, and a second save updates
 * rather than duplicates. Concrete subclasses bind the contract to the real adapter against
 * the test PostgreSQL database and own the row cleanup.
 */
abstract class AbstractOrderRepositoryContractTest extends KernelTestCase
{
    /** @var list<string> */
    protected array $createdOrderIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function repository(): OrderRepository
    {
        $repository = static::getContainer()->get(OrderRepository::class);
        \assert($repository instanceof OrderRepository);

        return $repository;
    }

    /** A fresh aggregate with one priced line. The subclass must clean up its rows. */
    protected function newOrder(string $orderId, string $orderNumber): Order
    {
        $currency = Currency::TND;

        return Order::placeOnline(
            new OrderId($orderId),
            Uuid::v7()->toRfc4122(),
            $orderNumber,
            Money::ofMinor(12900, $currency),
            Money::ofMinor(0, $currency),
            Money::ofMinor(700, $currency),
            Money::ofMinor(13600, $currency),
            'home',
            'cod',
            null,
            [
                OrderLine::snapshot(
                    Uuid::v7()->toRfc4122(),
                    'fauteuil',
                    'Fauteuil',
                    null,
                    Money::ofMinor(12900, $currency),
                    1,
                ),
            ],
            new \DateTimeImmutable(),
        );
    }

    protected function cleanup(string $orderId): void
    {
        $connection = static::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $connection->executeStatement('DELETE FROM ordering_order_lines WHERE order_id = :id', ['id' => $orderId]);
        $connection->executeStatement('DELETE FROM ordering_order_audit_trail WHERE order_id = :id', ['id' => $orderId]);
        $connection->executeStatement('DELETE FROM ordering_orders WHERE id = :id', ['id' => $orderId]);
    }

    public function testSaveThenGetReturnsTheSameAggregate(): void
    {
        $orderId = Uuid::v7()->toRfc4122();
        $this->createdOrderIds[] = $orderId;

        $this->repository()->save($this->newOrder($orderId, 'ZG-CONTRACT-O1'));

        $read = $this->repository()->get(new OrderId($orderId));

        self::assertSame($orderId, $read->id()->toString());
        self::assertSame('ZG-CONTRACT-O1', $read->orderNumber());
        self::assertSame(1, $read->lines()[0]->quantity());
    }

    public function testGetOnAMissingIdThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->repository()->get(new OrderId(Uuid::v7()->toRfc4122()));
    }

    public function testSaveTwiceUpdatesRatherThanDuplicates(): void
    {
        $orderId = Uuid::v7()->toRfc4122();
        $this->createdOrderIds[] = $orderId;
        $order = $this->newOrder($orderId, 'ZG-CONTRACT-O2');

        $this->repository()->save($order);
        $this->repository()->save($order);

        $connection = static::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);

        $count = $connection->fetchOne(
            'SELECT COUNT(*) FROM ordering_orders WHERE id = :id',
            ['id' => $orderId],
        );
        self::assertSame(1, (int) $count, 'a second save updates the row instead of duplicating it');
    }
}
