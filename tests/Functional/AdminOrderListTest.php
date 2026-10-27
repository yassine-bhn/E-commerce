<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use App\Ordering\Domain\Model\OrderStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * GET /api/v1/admin/orders — the dashboard list of every order.
 */
final class AdminOrderListTest extends WebTestCase
{
    /** @var list<string> */
    private array $userIds = [];
    /** @var list<string> */
    private array $orderIds = [];

    protected function tearDown(): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        if ([] !== $this->orderIds) {
            $connection->executeStatement('DELETE FROM ordering_order_lines WHERE order_id IN (:v)', ['v' => $this->orderIds], ['v' => ArrayParameterType::STRING]);
            $connection->executeStatement('DELETE FROM ordering_orders WHERE id IN (:v)', ['v' => $this->orderIds], ['v' => ArrayParameterType::STRING]);
        }
        if ([] !== $this->userIds) {
            $connection->executeStatement('DELETE FROM customer_profile WHERE id IN (:v)', ['v' => $this->userIds], ['v' => ArrayParameterType::STRING]);
        }

        parent::tearDown();
    }

    private function createUser(string $role, string $name = 'Order List Test'): CustomerUser
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $id = Uuid::v4()->toRfc4122();
        $email = sprintf('admin.orders.%s@example.com', substr($id, 0, 8));
        $connection->insert('customer_profile', [
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'status' => 'active',
        ]);
        $this->userIds[] = $id;

        return new CustomerUser($id, $email, null, $role, 'active');
    }

    private function insertOrder(string $number, OrderStatus $status, string $source, string $customerId, ?string $contactName, ?string $contactPhone, int $total, string $placedAt): string
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $id = Uuid::v7()->toRfc4122();
        $connection->insert('ordering_orders', [
            'id' => $id,
            'customer_id' => $customerId,
            'status' => $status->value,
            'version' => 1,
            'order_number' => $number,
            'order_source' => $source,
            'customer_name' => $contactName,
            'customer_phone' => $contactPhone,
            'subtotal_minor' => $total,
            'discount_minor' => 0,
            'delivery_minor' => 0,
            'total_minor' => $total,
            'currency' => 'TND',
            'delivery_method' => 'home',
            'payment_method' => 'Espèces à la livraison',
            'placed_at' => $placedAt,
        ]);
        $connection->insert('ordering_order_lines', [
            'id' => Uuid::v7()->toRfc4122(),
            'order_id' => $id,
            'product_slug' => 'test-product',
            'product_name' => 'Produit de test',
            'variant_label' => null,
            'unit_price_minor' => $total,
            'currency' => 'TND',
            'quantity' => 1,
        ]);
        $this->orderIds[] = $id;

        return $id;
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    public function testGuestAndCustomerCannotListOrders(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/admin/orders');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $client->loginUser($this->createUser('ROLE_USER'));
        $client->request('GET', '/api/v1/admin/orders');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAdminListsEveryOrderWithFiltersSortAndPagination(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));

        $customer = $this->createUser('ROLE_USER', 'Alice Online');
        $prefix = 'ZG-T'.strtoupper(substr(str_replace('-', '', Uuid::v4()->toRfc4122()), 0, 6));

        $this->insertOrder($prefix.'-A', OrderStatus::PENDING, 'ONLINE', $customer->getId(), null, null, 300, '2026-10-01 10:00:00+00');
        $this->insertOrder($prefix.'-B', OrderStatus::PAID, 'PHONE', Uuid::v4()->toRfc4122(), 'Bob Phone', '+21620000001', 100, '2026-10-02 10:00:00+00');
        $this->insertOrder($prefix.'-C', OrderStatus::SHIPPED, 'WHATSAPP', Uuid::v4()->toRfc4122(), 'Carla WhatsApp', '+21620000002', 200, '2026-10-03 10:00:00+00');

        // Pagination: 3 matches, 2 per page.
        $client->request('GET', '/api/v1/admin/orders', ['q' => $prefix, 'size' => 2, 'page' => 1]);
        self::assertResponseIsSuccessful();
        $page = $this->json($client);
        self::assertSame(3, $page['meta']['total']);
        self::assertSame(2, $page['meta']['totalPages']);
        self::assertTrue($page['meta']['hasMore']);
        self::assertCount(2, $page['items']);
        // Newest first by default.
        self::assertSame($prefix.'-C', $page['items'][0]['orderNumber']);

        $client->request('GET', '/api/v1/admin/orders', ['q' => $prefix, 'size' => 2, 'page' => 2]);
        self::assertCount(1, $this->json($client)['items']);

        // Sort by total.
        $client->request('GET', '/api/v1/admin/orders', ['q' => $prefix, 'sort' => 'totalAsc']);
        self::assertSame([$prefix.'-B', $prefix.'-C', $prefix.'-A'], array_column($this->json($client)['items'], 'orderNumber'));

        // Status filter, by slug.
        $client->request('GET', '/api/v1/admin/orders', ['q' => $prefix, 'status' => 'paid']);
        $paid = $this->json($client);
        self::assertSame(1, $paid['meta']['total']);
        self::assertSame($prefix.'-B', $paid['items'][0]['orderNumber']);
        self::assertSame('Acceptée', $paid['items'][0]['statusLabel']);

        // An unknown status answers an empty page instead of dropping the filter.
        $client->request('GET', '/api/v1/admin/orders', ['q' => $prefix, 'status' => 'nope']);
        self::assertSame(0, $this->json($client)['meta']['total']);

        // Source filter.
        $client->request('GET', '/api/v1/admin/orders', ['q' => $prefix, 'source' => 'WHATSAPP']);
        self::assertSame([$prefix.'-C'], array_column($this->json($client)['items'], 'orderNumber'));

        // Date range.
        $client->request('GET', '/api/v1/admin/orders', ['q' => $prefix, 'dateFrom' => '2026-10-02', 'dateTo' => '2026-10-02']);
        self::assertSame([$prefix.'-B'], array_column($this->json($client)['items'], 'orderNumber'));

        // Free text matches the contact too (proxy order snapshot and registered customer).
        $client->request('GET', '/api/v1/admin/orders', ['q' => '+21620000001']);
        self::assertContains($prefix.'-B', array_column($this->json($client)['items'], 'orderNumber'));
        $client->request('GET', '/api/v1/admin/orders', ['q' => 'Alice Online']);
        self::assertContains($prefix.'-A', array_column($this->json($client)['items'], 'orderNumber'));
    }

    public function testRowsCarryTheDataTheStatusActionsNeed(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_ADMIN'));

        $customer = $this->createUser('ROLE_USER', 'Dana Customer');
        $number = 'ZG-T'.strtoupper(substr(str_replace('-', '', Uuid::v4()->toRfc4122()), 0, 6));
        $orderId = $this->insertOrder($number, OrderStatus::PENDING, 'ONLINE', $customer->getId(), null, null, 450, '2026-10-05 09:00:00+00');

        $client->request('GET', '/api/v1/admin/orders', ['q' => $number]);
        self::assertResponseIsSuccessful();
        $item = $this->json($client)['items'][0];

        self::assertSame($orderId, $item['orderId']);
        self::assertSame(OrderStatus::PENDING->value, $item['status']);
        self::assertSame('pending', $item['statusSlug']);
        self::assertSame(1, $item['version']);
        self::assertSame('Dana Customer', $item['customer']['name']);
        self::assertSame($customer->getUserIdentifier(), $item['customer']['email']);
        self::assertSame(450, $item['total']);
        self::assertSame(1, $item['itemCount']);
        self::assertCount(1, $item['lines']);

        // A pending order can be accepted (paid), refused (cancelled) or marked failed — nothing else.
        $targets = array_column($item['allowedTransitions'], 'slug');
        sort($targets);
        self::assertSame(['cancelled', 'failed', 'paid'], $targets);
        self::assertSame(OrderStatus::PAID->value, $item['allowedTransitions'][0]['status']);
    }
}
