<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

use App\Ordering\Application\Port\AdminProxyOrderStore;
use App\Ordering\Application\Port\OrderCustomerReader;
use App\Ordering\Application\Port\OrderStateReader;
use App\Ordering\Application\Port\OrderTransitionStore;
use App\Ordering\Domain\Model\OrderStatus;
use App\Ordering\Infrastructure\Persistence\DbalAdminProxyOrderStore;
use App\Ordering\Infrastructure\Persistence\DbalOrderCustomerAdapter;
use App\Ordering\Infrastructure\Persistence\DbalOrderStateAdapter;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * solid Task 3.3 — the ordering state adapters against PostgreSQL: the guarded transition and
 * its audit row land exactly the columns the inline handler SQL wrote, and the optimistic
 * version check refuses a stale write.
 */
final class OrderStateAdapterTest extends KernelTestCase
{
    /** @var list<string> */
    private array $createdOrderIds = [];

    protected function tearDown(): void
    {
        if ([] !== $this->createdOrderIds) {
            $connection = self::connection();
            $connection->executeStatement(
                'DELETE FROM ordering_order_audit_trail WHERE order_id IN (:ids)',
                ['ids' => $this->createdOrderIds],
                ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
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

        $this->createdOrderIds = [];

        parent::tearDown();
    }

    public function testFindAnswersTheStatusVersionPairOrNull(): void
    {
        $adapter = $this->stateAdapter();
        $orderId = $this->newOrder(OrderStatus::PENDING->value, 3);

        $state = $adapter->find($orderId);
        self::assertNotNull($state);
        self::assertSame(OrderStatus::PENDING, $state->status);
        self::assertSame(3, $state->version);

        self::assertNull($adapter->find(Uuid::v7()->toRfc4122()), 'a missing order reads as null');
    }

    public function testTransitionMovesTheRowWhenTheVersionMatchesAndRefusesStaleWrites(): void
    {
        $adapter = $this->stateAdapter();
        $orderId = $this->newOrder(OrderStatus::PENDING->value, 1);

        self::assertTrue($adapter->transition($orderId, 1, OrderStatus::PAID, 2), 'the guarded write happens');
        self::assertSame(
            OrderStatus::PAID->value,
            (string) self::connection()->fetchOne('SELECT status FROM ordering_orders WHERE id = :id', ['id' => $orderId]),
        );
        self::assertSame(2, (int) self::connection()->fetchOne('SELECT version FROM ordering_orders WHERE id = :id', ['id' => $orderId]));

        self::assertFalse($adapter->transition($orderId, 1, OrderStatus::CANCELLED, 3), 'a stale version writes nothing');
        self::assertSame(2, (int) self::connection()->fetchOne('SELECT version FROM ordering_orders WHERE id = :id', ['id' => $orderId]));
    }

    public function testAppendAuditWritesOneTrailRow(): void
    {
        $adapter = $this->stateAdapter();
        $orderId = $this->newOrder(OrderStatus::PENDING->value, 1);

        $adapter->appendAudit($orderId, OrderStatus::PENDING, OrderStatus::PAID, 1, 2, 'admin-1', 'Confirmed by admin');

        $row = self::connection()->fetchAssociative(
            'SELECT from_status, to_status, from_version, to_version, changed_by, reason FROM ordering_order_audit_trail WHERE order_id = :id',
            ['id' => $orderId],
        );
        self::assertNotFalse($row, 'the audit row is written');
        self::assertSame(OrderStatus::PENDING->value, $row['from_status']);
        self::assertSame(OrderStatus::PAID->value, $row['to_status']);
        self::assertSame(1, (int) $row['from_version']);
        self::assertSame(2, (int) $row['to_version']);
        self::assertSame('admin-1', $row['changed_by']);
        self::assertSame('Confirmed by admin', $row['reason']);
    }

    public function testCustomerReaderAnswersOwnerAndStatus(): void
    {
        $adapter = static::getContainer()->get(OrderCustomerReader::class);
        \assert($adapter instanceof DbalOrderCustomerAdapter);
        $customerId = Uuid::v7()->toRfc4122();
        $orderId = $this->newOrder(OrderStatus::PENDING->value, 1, $customerId);

        $customer = $adapter->find($orderId);
        self::assertNotNull($customer);
        self::assertSame($customerId, $customer->customerId);
        self::assertSame(OrderStatus::PENDING, $customer->status);

        self::assertNull($adapter->find(Uuid::v7()->toRfc4122()));
    }

    public function testProxyOrderStoreWritesOrderLinesAndFirstAuditEntry(): void
    {
        $store = static::getContainer()->get(AdminProxyOrderStore::class);
        \assert($store instanceof DbalAdminProxyOrderStore);
        $orderId = Uuid::v7()->toRfc4122();
        $this->createdOrderIds[] = $orderId;

        $store->create(new \App\Ordering\Application\Dto\AdminProxyOrderData(
            orderId: $orderId,
            guestCustomerId: Uuid::v7()->toRfc4122(),
            orderNumber: 'ZG-PROXY-'.substr($orderId, 0, 6),
            orderSource: \App\Ordering\Domain\Model\OrderSource::PHONE->value,
            customerName: 'Client Telephone',
            customerPhone: '+21671000001',
            shippingAddress: 'Rue de la Plage, Sousse',
            createdByAdminId: 'admin-1',
            adminNotes: 'Commande passe au telephone',
            subtotalMinor: 12900,
            discountMinor: 0,
            deliveryMinor: 700,
            totalMinor: 13600,
            currency: 'TND',
            deliveryMethod: 'home',
            paymentMethod: 'cod',
            promoCode: null,
            lines: [[
                'slug' => 'fauteuil',
                'name' => 'Fauteuil',
                'variant' => null,
                'unitPriceMinor' => 12900,
                'quantity' => 1,
            ]],
        ));

        $order = self::connection()->fetchAssociative(
            'SELECT status, version, order_source, total_minor, customer_name FROM ordering_orders WHERE id = :id',
            ['id' => $orderId],
        );
        self::assertNotFalse($order, 'the order row is written');
        self::assertSame(OrderStatus::PENDING->value, $order['status']);
        self::assertSame(1, (int) $order['version']);
        self::assertSame('PHONE', $order['order_source']);
        self::assertSame(13600, (int) $order['total_minor']);

        self::assertSame(
            1,
            (int) self::connection()->fetchOne('SELECT COUNT(*) FROM ordering_order_lines WHERE order_id = :id', ['id' => $orderId]),
            'the priced line is written',
        );

        $audit = self::connection()->fetchAssociative(
            'SELECT from_status, to_status, to_version, changed_by, reason FROM ordering_order_audit_trail WHERE order_id = :id',
            ['id' => $orderId],
        );
        self::assertNotFalse($audit);
        self::assertNull($audit['from_status']);
        self::assertSame(OrderStatus::PENDING->value, $audit['to_status']);
        self::assertSame('admin-1', $audit['changed_by']);
        self::assertSame('Proxy order created via PHONE', $audit['reason']);
    }

    public function testEveryOrderingStatePortResolvesToItsAdapter(): void
    {
        $stateAdapter = static::getContainer()->get(OrderStateReader::class);
        self::assertSame($stateAdapter, static::getContainer()->get(OrderTransitionStore::class), 'state reads and transitions share the adapter');

        $customerAdapter = static::getContainer()->get(OrderCustomerReader::class);
        self::assertInstanceOf(DbalOrderCustomerAdapter::class, $customerAdapter);
    }

    private function stateAdapter(): DbalOrderStateAdapter
    {
        $adapter = static::getContainer()->get(OrderStateReader::class);
        \assert($adapter instanceof DbalOrderStateAdapter);

        return $adapter;
    }

    private function newOrder(string $status, int $version, ?string $customerId = null): string
    {
        $orderId = Uuid::v7()->toRfc4122();
        $this->createdOrderIds[] = $orderId;
        self::connection()->insert('ordering_orders', [
            'id' => $orderId,
            'customer_id' => $customerId ?? Uuid::v7()->toRfc4122(),
            'status' => $status,
            'version' => $version,
            'order_number' => 'ZG-ADAPT-'.substr($orderId, 0, 6),
            'total_minor' => 5000,
            'currency' => 'TND',
        ]);

        return $orderId;
    }

    private static function connection(): Connection
    {
        self::bootKernel();

        $connection = static::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        return $connection;
    }
}
