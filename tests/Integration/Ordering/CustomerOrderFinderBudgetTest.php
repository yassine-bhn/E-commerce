<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

use App\Ordering\Application\Query\ListOrdersForCustomer;
use App\Ordering\Infrastructure\ReadModel\CustomerOrderFinder;
use App\Shared\Application\Pagination\CursorRequest;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * R7 / PRF-001 — a page of N orders costs exactly two statements: one for the page and one
 * `IN` statement for every line of that page (the per-order line query is gone).
 */
final class CustomerOrderFinderBudgetTest extends KernelTestCase
{
    private CountingConnection $connection;

    private string $customerId;

    /** @var list<string> */
    private array $orderIds = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $connection = static::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);
        $this->connection = CountingConnection::wrap($connection);

        $this->customerId = Uuid::v4()->toRfc4122();
        $this->connection->insert('customer_profile', [
            'id' => $this->customerId,
            'name' => 'Budget Orders',
            'email' => sprintf('budget.orders.%s@example.com', substr($this->customerId, 0, 8)),
            'password_hash' => password_hash('Budget!234', PASSWORD_BCRYPT),
            'role' => 'ROLE_USER',
            'status' => 'active',
        ]);

        foreach ([1, 2, 3] as $index) {
            $orderId = $this->insertOrder(sprintf('ZG-BUDGET-%06d', $index), sprintf('2026-03-%02d 09:00:00+00', $index));
            foreach ([1, 2] as $line) {
                $this->connection->insert('ordering_order_lines', [
                    'id' => Uuid::v4()->toRfc4122(),
                    'order_id' => $orderId,
                    'product_slug' => sprintf('budget-product-%d-%d', $index, $line),
                    'product_name' => sprintf('Budget Product %d-%d', $index, $line),
                    'variant_label' => null,
                    'unit_price_minor' => 5_000,
                    'currency' => 'TND',
                    'quantity' => 1,
                ], ['unit_price_minor' => ParameterType::INTEGER, 'quantity' => ParameterType::INTEGER]);
            }
        }
    }

    protected function tearDown(): void
    {
        if ([] !== $this->orderIds) {
            $this->connection->executeStatement(
                'DELETE FROM ordering_order_lines WHERE order_id IN (:ids)',
                ['ids' => $this->orderIds],
                ['ids' => ArrayParameterType::STRING],
            );
            $this->connection->executeStatement(
                'DELETE FROM ordering_orders WHERE id IN (:ids)',
                ['ids' => $this->orderIds],
                ['ids' => ArrayParameterType::STRING],
            );
        }
        $this->orderIds = [];

        $this->connection->executeStatement(
            'DELETE FROM customer_profile WHERE id = :id',
            ['id' => $this->customerId],
        );

        parent::tearDown();
    }

    public function testAPageWithLinesCostsTwoStatementsAndCarriesEveryLine(): void
    {
        $finder = new CustomerOrderFinder($this->connection);

        $this->connection->reset();
        $first = $finder(new ListOrdersForCustomer($this->customerId, new CursorRequest(2, null, 100)));

        self::assertSame(2, $this->connection->calls['fetchAllAssociative'] ?? 0, 'one page query + one IN query for its lines');
        self::assertCount(2, $first->items);
        self::assertTrue($first->hasMore);

        foreach ($first->items as $item) {
            self::assertCount(2, $item['lines'], 'all lines of the page arrive from the single IN statement');
        }

        $this->connection->reset();
        $second = $finder(new ListOrdersForCustomer($this->customerId, new CursorRequest(2, $first->nextCursor, 100)));

        self::assertSame(2, $this->connection->calls['fetchAllAssociative'] ?? 0, 'the second page costs the same');
        self::assertCount(1, $second->items);
        self::assertFalse($second->hasMore);
        self::assertCount(2, $second->items[0]['lines']);
    }

    public function testAnEmptyPageCostsASingleStatement(): void
    {
        $finder = new CustomerOrderFinder($this->connection);

        $this->connection->reset();
        $page = $finder(new ListOrdersForCustomer($this->customerId, new CursorRequest(2, null, 100), 'annulee'));

        self::assertSame(1, $this->connection->calls['fetchAllAssociative'] ?? 0, 'no orders means no line query');
        self::assertSame([], $page->items);
    }

    private function insertOrder(string $orderNumber, string $placedAt): string
    {
        $id = Uuid::v4()->toRfc4122();

        $this->connection->insert('ordering_orders', [
            'id' => $id,
            'customer_id' => $this->customerId,
            'status' => '待支付',
            'order_number' => $orderNumber,
            'subtotal_minor' => 10_000,
            'discount_minor' => 0,
            'delivery_minor' => 700,
            'total_minor' => 10_700,
            'currency' => 'TND',
            'delivery_method' => 'home',
            'payment_method' => 'card',
            'placed_at' => $placedAt,
        ], [
            'subtotal_minor' => ParameterType::INTEGER,
            'discount_minor' => ParameterType::INTEGER,
            'delivery_minor' => ParameterType::INTEGER,
            'total_minor' => ParameterType::INTEGER,
        ]);
        $this->orderIds[] = $id;

        return $id;
    }
}

/**
 * Delegating wrapper that records which DBAL fetch methods the finder uses.
 */
final class CountingConnection extends Connection
{
    /** @var array<string, int> */
    public array $calls = [];

    private Connection $inner;

    public static function wrap(Connection $inner): self
    {
        $spy = new self($inner->getParams(), $inner->getDriver());
        $spy->inner = $inner;

        return $spy;
    }

    public function reset(): void
    {
        $this->calls = [];
    }

    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        $this->calls['fetchAllAssociative'] = ($this->calls['fetchAllAssociative'] ?? 0) + 1;

        return $this->inner->fetchAllAssociative($query, $params, $types);
    }

    public function insert(string $table, array $data, array $types = []): int|string
    {
        return $this->inner->insert($table, $data, $types);
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        return $this->inner->executeStatement($sql, $params, $types);
    }
}
