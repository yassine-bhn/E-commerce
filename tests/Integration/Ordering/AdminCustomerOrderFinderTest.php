<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

use App\Ordering\Application\Query\ListOrdersForAdminRequestedCustomer;
use App\Ordering\Domain\Model\OrderSource;
use App\Ordering\Domain\Model\OrderStatus;
use App\Ordering\Infrastructure\ReadModel\AdminCustomerOrderFinder;
use App\Ordering\Infrastructure\ReadModel\AdminCustomerOrderLineView;
use App\Ordering\Infrastructure\ReadModel\AdminCustomerOrderView;
use App\Shared\Application\Pagination\CursorPage;
use App\Shared\Application\Pagination\CursorRequest;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Uid\Uuid;

/**
 * WP4 / R16 — the admin requested-customer order read against the real read tables:
 * newest-first, the target customer's rows only, integer minor units, line items attached.
 */
final class AdminCustomerOrderFinderTest extends KernelTestCase
{
    private const TARGET = '019acc00-0000-7000-8000-0000000000aa';
    private const OTHER = '019acc00-0000-7000-8000-0000000000bb';
    private const ADMIN = '019acc00-0000-7000-8000-0000000000cc';

    /** @var list<string> */
    private array $orderIds = [];

    /** @var list<string> */
    private array $customerIds = [];

    protected function tearDown(): void
    {
        $connection = $this->connection();

        if ([] !== $this->orderIds) {
            $connection->executeStatement(
                'DELETE FROM ordering_order_lines WHERE order_id IN (:ids)',
                ['ids' => $this->orderIds],
                ['ids' => ArrayParameterType::STRING],
            );
            $connection->executeStatement(
                'DELETE FROM ordering_orders WHERE id IN (:ids)',
                ['ids' => $this->orderIds],
                ['ids' => ArrayParameterType::STRING],
            );
        }

        if ([] !== $this->customerIds) {
            $connection->executeStatement(
                'DELETE FROM customer_profile WHERE id IN (:ids)',
                ['ids' => $this->customerIds],
                ['ids' => ArrayParameterType::STRING],
            );
        }

        parent::tearDown();
    }

    public function testItReturnsThatCustomersOrdersNewestFirstWithLineItemsAndIntegerMinorUnits(): void
    {
        self::bootKernel();
        $this->assumeSchema();
        $this->purgeFixtures();

        $this->seedProfile(self::TARGET, 'Target Buyer');
        $this->seedProfile(self::OTHER, 'Other Buyer');
        $this->seedProfile(self::ADMIN, 'Dashboard Admin', 'ROLE_SUPER_ADMIN');

        $old = $this->seedOrder(self::TARGET, 'ZG-OLD-001', '2026-03-01 09:00:00+01', 12_500, 0, 1_500, 14_000);
        $mid = $this->seedOrder(self::TARGET, 'ZG-MID-002', '2026-04-15 12:00:00+01', 5_000, 500, 0, 4_500);
        $new = $this->seedOrder(self::TARGET, 'ZG-NEW-003', '2026-06-15 18:30:00+01', 30_000, 2_000, 0, 28_000);
        $this->seedOrder(self::OTHER, 'ZG-OTHER-004', '2026-07-20 08:00:00+01', 9_900, 0, 0, 9_900);

        $this->seedLine($old, 'baignoire-d-code', 'Baignoire D Code', '80 L', 12_500, 1);
        $this->seedLine($mid, 'mitigeur-thermostatique', 'Mitigeur Thermostatique', null, 5_000, 1);
        $this->seedLine($new, 'wc-suspendu', 'WC Suspendu', null, 15_000, 2);
        $this->seedLine($new, 'douche-thermostatique', 'Douche Thermostatique', 'Chrome', 0, 1);

        $page = $this->query(new ListOrdersForAdminRequestedCustomer(self::ADMIN, true, self::TARGET));
        self::assertSame(20, $page->limit);
        self::assertFalse($page->hasMore);
        self::assertNull($page->nextCursor);

        /** @var list<AdminCustomerOrderView> $orders */
        $orders = $page->items;

        self::assertSame(['ZG-NEW-003', 'ZG-MID-002', 'ZG-OLD-001'], array_map(static fn (AdminCustomerOrderView $order): string => $order->orderNumber, $orders));
        self::assertSame(['wc-suspendu', 'douche-thermostatique'], array_map(static fn (AdminCustomerOrderLineView $line): string => $line->productSlug, $orders[0]->lines));
        self::assertSame(['mitigeur-thermostatique'], array_map(static fn (AdminCustomerOrderLineView $line): string => $line->productSlug, $orders[1]->lines));
        self::assertSame(['baignoire-d-code'], array_map(static fn (AdminCustomerOrderLineView $line): string => $line->productSlug, $orders[2]->lines));
        self::assertSame(30_000, $orders[0]->subtotalMinor);
        self::assertSame(2_000, $orders[0]->discountMinor);
        self::assertSame(28_000, $orders[0]->totalMinor);
        self::assertSame('TND', $orders[0]->currency);
        self::assertSame(15_000, $orders[0]->lines[0]->unitPriceMinor);
        self::assertSame(2, $orders[0]->lines[0]->quantity);
        self::assertSame('2026-06-15', $orders[0]->date);
        self::assertSame(OrderStatus::PENDING->value, $orders[0]->status);
        self::assertSame('En préparation', $orders[0]->statusLabel);
    }

    public function testItFetchesTheLinesOfEveryOrderInOneSingleStatement(): void
    {
        self::bootKernel();
        $this->assumeSchema();
        $this->purgeFixtures();

        $this->seedProfile(self::TARGET, 'Target Buyer');
        $old = $this->seedOrder(self::TARGET, 'ZG-OLD-001', '2026-03-01 09:00:00+01', 12_500, 0, 1_500, 14_000);
        $mid = $this->seedOrder(self::TARGET, 'ZG-MID-002', '2026-04-15 12:00:00+01', 5_000, 500, 0, 4_500);
        $this->seedOrder(self::TARGET, 'ZG-NEW-003', '2026-06-15 18:30:00+01', 30_000, 2_000, 0, 28_000);
        $this->seedLine($old, 'baignoire-d-code', 'Baignoire D Code', '80 L', 12_500, 1);
        $this->seedLine($mid, 'mitigeur-thermostatique', 'Mitigeur Thermostatique', null, 5_000, 1);

        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $statements = [];

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $sql = $context['sql'] ?? null;
                if (is_string($sql) && str_starts_with((string) $message, 'Executing')) {
                    $this->statements[] = $sql;
                }
            }
        };

        $config = new DBALConfiguration();
        $config->setMiddlewares([new LoggingMiddleware($logger)]);

        $connection = $this->connection();
        /** @var array<string, mixed> $params */
        $params = $connection->getParams();
        unset($params['url'], $params['dbname_suffix']);
        $params['dbname'] = (string) $connection->fetchOne('SELECT current_database()');

        $finder = new AdminCustomerOrderFinder(DriverManager::getConnection($params, $config));
        $page = $finder(new ListOrdersForAdminRequestedCustomer(self::ADMIN, true, self::TARGET));

        self::assertCount(3, $page->items);
        self::assertCount(3, $logger->statements, 'One existence check, one order query and one line query — never one per order.');
        self::assertSame(1, count(array_filter($logger->statements, static fn (string $sql): bool => str_contains($sql, 'ordering_order_lines'))));
        self::assertSame([], array_values(array_filter(
            $logger->statements,
            static fn (string $sql): bool => 1 === preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i', $sql),
        )), 'QRY-001: the read side must not execute a write statement.');
    }

    public function testItExcludesPhoneAndWhatsappProxyOrdersOfTheSameCustomer(): void
    {
        self::bootKernel();
        $this->assumeSchema();
        $this->purgeFixtures();

        $this->seedProfile(self::TARGET, 'Target Buyer');
        $online = $this->seedOrder(self::TARGET, 'ZG-NEW-003', '2026-06-15 18:30:00+01', 30_000, 0, 0, 30_000);
        // The adversarial case: a proxy order that carries the registered customer's own id.
        $this->seedProxyOrder(self::TARGET, 'ZG-WA-777777', '2026-08-01 10:00:00+01', OrderSource::WHATSAPP);
        $this->seedProxyOrder(self::TARGET, 'ZG-TEL-888888', '2026-08-02 10:00:00+01', OrderSource::PHONE);
        $this->seedLine($online, 'wc-suspendu', 'WC Suspendu', null, 30_000, 1);

        $page = (new AdminCustomerOrderFinder($this->connection()))(new ListOrdersForAdminRequestedCustomer(self::ADMIN, true, self::TARGET));

        self::assertSame(['ZG-NEW-003'], array_map(static fn (AdminCustomerOrderView $order): string => $order->orderNumber, $page->items));
    }

    public function testTheFiltersAndTheKeysetBoundThePageAndKeepItContiguous(): void
    {
        self::bootKernel();
        $this->assumeSchema();
        $this->purgeFixtures();

        $this->seedProfile(self::TARGET, 'Target Buyer');
        $this->seedProfile(self::OTHER, 'Other Buyer');
        $this->seedOrder(self::TARGET, 'ZG-FILTER-001', '2026-05-01 09:00:00+01', 1_000, 0, 0, 1_000);
        $this->seedOrder(self::TARGET, 'ZG-FILTER-002', '2026-05-02 09:00:00+01', 2_000, 0, 0, 2_000, OrderStatus::PAID);
        $this->seedOrder(self::TARGET, 'ZG-FILTER-003', '2026-05-03 09:00:00+01', 3_000, 0, 0, 3_000, OrderStatus::SHIPPED);
        $this->seedOrder(self::TARGET, 'ZG-FILTER-004', '2026-05-04 09:00:00+01', 4_000, 0, 0, 4_000, OrderStatus::CANCELLED);
        $this->seedOrder(self::TARGET, 'ZG-FILTER-005', '2026-05-05 09:00:00+01', 5_000, 0, 0, 5_000);
        // Same shape, other owner and the newest placed_at: a scoping bug would surface it first.
        $this->seedOrder(self::OTHER, 'ZG-OTHER-004', '2026-05-06 09:00:00+01', 9_900, 0, 0, 9_900);

        $first = $this->query(new ListOrdersForAdminRequestedCustomer(
            requesterId: self::ADMIN,
            requesterIsAdmin: true,
            customerId: self::TARGET,
            cursor: new CursorRequest(2, null, ListOrdersForAdminRequestedCustomer::MAX_LIMIT),
            status: 'en_preparation',
        ));

        self::assertSame(
            ['ZG-FILTER-005', 'ZG-FILTER-002'],
            array_map(static fn (AdminCustomerOrderView $order): string => $order->orderNumber, $first->items),
            'pending and paid are both "En préparation", newest first, and only for that customer',
        );
        self::assertSame(2, $first->limit);
        self::assertTrue($first->hasMore);
        self::assertNotNull($first->nextCursor);

        $second = $this->query(new ListOrdersForAdminRequestedCustomer(
            requesterId: self::ADMIN,
            requesterIsAdmin: true,
            customerId: self::TARGET,
            cursor: new CursorRequest(2, $first->nextCursor, ListOrdersForAdminRequestedCustomer::MAX_LIMIT),
            status: 'en_preparation',
        ));

        self::assertSame(
            ['ZG-FILTER-001'],
            array_map(static fn (AdminCustomerOrderView $order): string => $order->orderNumber, $second->items),
            'the keyset resumes strictly after the last delivered row and nothing is skipped',
        );
        self::assertFalse($second->hasMore);
        self::assertNull($second->nextCursor);

        $dateRange = $this->query(new ListOrdersForAdminRequestedCustomer(
            requesterId: self::ADMIN,
            requesterIsAdmin: true,
            customerId: self::TARGET,
            cursor: new CursorRequest(100, null, ListOrdersForAdminRequestedCustomer::MAX_LIMIT),
            dateFrom: new \DateTimeImmutable('2026-05-02'),
            dateTo: new \DateTimeImmutable('2026-05-04'),
        ));

        self::assertSame(
            ['ZG-FILTER-004', 'ZG-FILTER-003', 'ZG-FILTER-002'],
            array_map(static fn (AdminCustomerOrderView $order): string => $order->orderNumber, $dateRange->items),
            'the date range is inclusive on both ends and stays inside the requested customer',
        );

        $byNumber = $this->query(new ListOrdersForAdminRequestedCustomer(
            requesterId: self::ADMIN,
            requesterIsAdmin: true,
            customerId: self::TARGET,
            q: 'FILTER-003',
        ));

        self::assertSame(
            ['ZG-FILTER-003'],
            array_map(static fn (AdminCustomerOrderView $order): string => $order->orderNumber, $byNumber->items),
        );
    }

    private function query(ListOrdersForAdminRequestedCustomer $query): CursorPage
    {
        /** @var MessageBusInterface $queryBus */
        $queryBus = static::getContainer()->get('messenger.bus.query');
        $handled = $queryBus->dispatch($query)->last(HandledStamp::class);
        self::assertNotNull($handled, 'The read must be registered on the query.bus.');

        /** @var CursorPage $page */
        $page = $handled->getResult();

        return $page;
    }

    private function connection(): Connection
    {
        $connection = static::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);

        return $connection;
    }

    private function purgeFixtures(): void
    {
        $connection = $this->connection();
        /** @var list<string> $ids */
        $ids = $connection->fetchFirstColumn(
            'SELECT id FROM ordering_orders WHERE order_number IN (:numbers)',
            ['numbers' => ['ZG-OLD-001', 'ZG-MID-002', 'ZG-NEW-003', 'ZG-OTHER-004', 'ZG-FILTER-001', 'ZG-FILTER-002', 'ZG-FILTER-003', 'ZG-FILTER-004', 'ZG-FILTER-005']],
            ['numbers' => ArrayParameterType::STRING],
        );

        if ([] !== $ids) {
            $connection->executeStatement('DELETE FROM ordering_order_lines WHERE order_id IN (:ids)', ['ids' => $ids], ['ids' => ArrayParameterType::STRING]);
            $connection->executeStatement('DELETE FROM ordering_orders WHERE id IN (:ids)', ['ids' => $ids], ['ids' => ArrayParameterType::STRING]);
        }

        $connection->executeStatement(
            'DELETE FROM customer_profile WHERE id IN (:ids)',
            ['ids' => [self::TARGET, self::OTHER, self::ADMIN]],
            ['ids' => ArrayParameterType::STRING],
        );
    }

    private function assumeSchema(): void
    {
        $migrated = $this->connection()->fetchOne(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = :table',
            ['table' => 'ordering_orders'],
        );

        if (false === $migrated) {
            self::markTestSkipped('PostgreSQL schema not migrated (run doctrine:migrations:migrate).');
        }
    }

    private function seedProfile(string $id, string $name, string $role = 'ROLE_USER'): void
    {
        $this->customerIds[] = $id;
        $this->connection()->insert('customer_profile', [
            'id' => $id,
            'name' => $name,
            'email' => sprintf('%s.integration@example.com', strtolower(str_replace(' ', '-', $name))),
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function seedOrder(string $customerId, string $orderNumber, string $placedAt, int $subtotal, int $discount, int $delivery, int $total, OrderStatus $status = OrderStatus::PENDING): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->orderIds[] = $id;
        $this->connection()->insert('ordering_orders', [
            'id' => $id,
            'customer_id' => $customerId,
            'status' => $status->value,
            'version' => 1,
            'order_number' => $orderNumber,
            'order_source' => OrderSource::ONLINE->value,
            'subtotal_minor' => $subtotal,
            'discount_minor' => $discount,
            'delivery_minor' => $delivery,
            'total_minor' => $total,
            'currency' => 'TND',
            'delivery_method' => 'home',
            'payment_method' => 'Carte bancaire',
            'promo_code' => null,
            'placed_at' => $placedAt,
        ]);

        return $id;
    }

    private function seedProxyOrder(string $customerId, string $orderNumber, string $placedAt, OrderSource $source): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->orderIds[] = $id;
        $this->connection()->insert('ordering_orders', [
            'id' => $id,
            'customer_id' => $customerId,
            'status' => OrderStatus::PENDING->value,
            'version' => 1,
            'order_number' => $orderNumber,
            'order_source' => $source->value,
            'customer_name' => 'Guest By Phone',
            'customer_phone' => '+216 00 000 000',
            'shipping_address' => 'Avenue Habib Bourguiba, Tunis',
            'created_by_admin_id' => self::ADMIN,
            'subtotal_minor' => 1_000,
            'discount_minor' => 0,
            'delivery_minor' => 0,
            'total_minor' => 1_000,
            'currency' => 'TND',
            'delivery_method' => 'home',
            'payment_method' => 'Virement / Paiement à la livraison',
            'placed_at' => $placedAt,
        ]);

        return $id;
    }

    private function seedLine(string $orderId, string $slug, string $name, ?string $variant, int $unitPriceMinor, int $quantity): void
    {
        $this->connection()->insert('ordering_order_lines', [
            'id' => Uuid::v7()->toRfc4122(),
            'order_id' => $orderId,
            'product_slug' => $slug,
            'product_name' => $name,
            'variant_label' => $variant,
            'unit_price_minor' => $unitPriceMinor,
            'currency' => 'TND',
            'quantity' => $quantity,
        ]);
    }
}
