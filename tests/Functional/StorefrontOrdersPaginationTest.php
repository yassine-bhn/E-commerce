<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * R7 — `GET /api/v1/storefront/orders` paginates by keyset on `(placed_at, id)` and filters on
 * status, dates and order number, always scoped to the authenticated customer.
 */
final class StorefrontOrdersPaginationTest extends WebTestCase
{
    private KernelBrowser $client;

    private CustomerUser $customer;

    /** @var list<string> */
    private array $orderIds = [];

    /** @var list<string> */
    private array $customerIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->customer = $this->createCustomer('orders.tester');
        $this->client->loginUser($this->customer);

        // Five orders, oldest first, mixing the three storefront status families.
        $this->insertOrder('ZG-IT-100001', '已支付', '2026-09-28 09:00:00+00');
        $this->insertOrder('ZG-IT-100002', '待支付', '2026-10-01 09:00:00+00');
        $this->insertOrder('ZG-IT-100003', '已发货', '2026-10-03 09:00:00+00');
        $this->insertOrder('ZG-IT-100004', '已取消', '2026-10-05 09:00:00+00');
        $this->insertOrder('ZG-IT-200005', '待支付', '2026-10-07 09:00:00+00');
    }

    protected function tearDown(): void
    {
        $connection = self::connection();

        if ([] !== $this->orderIds) {
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

        $this->orderIds = [];
        $this->customerIds = [];

        parent::tearDown();
    }

    public function testTheFirstPageIsBoundedNewestFirstAndCarriesLines(): void
    {
        $this->client->request('GET', '/api/v1/storefront/orders?limit=2');
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> $data */
        $data = $this->json();

        self::assertSame(2, $data['meta']['limit']);
        self::assertTrue($data['meta']['hasMore']);
        self::assertIsString($data['meta']['nextCursor']);
        self::assertSame(['ZG-IT-200005', 'ZG-IT-100004'], array_column($data['items'], 'id'), 'placed_at DESC');
        self::assertSame('Line of ZG-IT-200005', $data['items'][0]['lines'][0]['slug']);
    }

    public function testWalkingTheCursorReturnsEveryOrderExactlyOnce(): void
    {
        $collected = [];
        $cursor = null;

        do {
            $url = '/api/v1/storefront/orders?limit=2';
            if (null !== $cursor) {
                $url .= '&cursor='.rawurlencode($cursor);
            }

            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            /** @var array<string, mixed> $data */
            $data = $this->json();

            foreach ($data['items'] as $item) {
                $collected[] = $item['id'];
            }

            $cursor = $data['meta']['nextCursor'];
        } while (null !== $cursor);

        self::assertSame(
            ['ZG-IT-200005', 'ZG-IT-100004', 'ZG-IT-100003', 'ZG-IT-100002', 'ZG-IT-100001'],
            $collected,
            'pages are contiguous and nothing is skipped',
        );
    }

    public function testTheStatusFilterUsesTheStorefrontVocabulary(): void
    {
        $this->client->request('GET', '/api/v1/storefront/orders?status=en_preparation');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = $this->json();
        self::assertSame(['ZG-IT-200005', 'ZG-IT-100002', 'ZG-IT-100001'], array_column($data['items'], 'id'), '待支付 and 已支付 are both "En préparation"');

        $this->client->request('GET', '/api/v1/storefront/orders?status=expediee');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = $this->json();
        self::assertSame(['ZG-IT-100003'], array_column($data['items'], 'id'));

        $this->client->request('GET', '/api/v1/storefront/orders?status=annulee');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = $this->json();
        self::assertSame(['ZG-IT-100004'], array_column($data['items'], 'id'));

        $this->client->request('GET', '/api/v1/storefront/orders?status=not-a-status');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = $this->json();
        self::assertSame([], $data['items'], 'an unknown status matches nothing instead of leaking rows');
    }

    public function testTheDateRangeIsInclusiveOnBothEnds(): void
    {
        $this->client->request('GET', '/api/v1/storefront/orders?dateFrom=2026-10-01&dateTo=2026-10-05');
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> $data */
        $data = $this->json();

        self::assertSame(
            ['ZG-IT-100004', 'ZG-IT-100003', 'ZG-IT-100002'],
            array_column($data['items'], 'id'),
            'dateTo includes the whole day and the range is scoped to the customer',
        );
    }

    public function testTheQFilterMatchesTheOrderNumber(): void
    {
        $this->client->request('GET', '/api/v1/storefront/orders?q=200005');
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> $data */
        $data = $this->json();
        self::assertSame(['ZG-IT-200005'], array_column($data['items'], 'id'));
    }

    public function testAnotherCustomersOrdersAreNeverVisible(): void
    {
        $other = $this->createCustomer('orders.other');
        $this->insertOrder('ZG-IT-999999', '待支付', '2026-10-09 09:00:00+00', $other->getId());

        $this->client->request('GET', '/api/v1/storefront/orders?limit=100');
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> $data */
        $data = $this->json();
        self::assertNotContains('ZG-IT-999999', array_column($data['items'], 'id'));
    }

    public function testAnInvalidCursorIsABadRequest(): void
    {
        $this->client->request('GET', '/api/v1/storefront/orders?cursor=not-base64');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame(['error' => 'Invalid cursor'], $this->json());
    }

    public function testTheLimitIsClampedInsteadOfRejected(): void
    {
        $this->client->request('GET', '/api/v1/storefront/orders?limit=0');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = $this->json();
        self::assertSame(1, $data['meta']['limit']);
        self::assertCount(1, $data['items']);
    }

    private function createCustomer(string $prefix): CustomerUser
    {
        $id = Uuid::v4()->toRfc4122();
        $email = sprintf('%s.%s@example.com', $prefix, substr($id, 0, 8));

        self::connection()->insert('customer_profile', [
            'id' => $id,
            'name' => 'Orders Tester',
            'email' => $email,
            'password_hash' => password_hash('Orders!234', PASSWORD_BCRYPT),
            'role' => 'ROLE_USER',
            'status' => 'active',
        ]);
        $this->customerIds[] = $id;

        return new CustomerUser($id, $email, null, 'ROLE_USER', 'active');
    }

    private function insertOrder(string $orderNumber, string $status, string $placedAt, ?string $customerId = null): void
    {
        $connection = self::connection();
        $orderId = Uuid::v4()->toRfc4122();

        $connection->insert(
            'ordering_orders',
            [
                'id' => $orderId,
                'customer_id' => $customerId ?? $this->customer->getId(),
                'status' => $status,
                'order_number' => $orderNumber,
                'subtotal_minor' => 10_000,
                'discount_minor' => 0,
                'delivery_minor' => 700,
                'total_minor' => 10_700,
                'currency' => 'TND',
                'delivery_method' => 'home',
                'payment_method' => 'card',
                'placed_at' => $placedAt,
            ],
            [
                'subtotal_minor' => ParameterType::INTEGER,
                'discount_minor' => ParameterType::INTEGER,
                'delivery_minor' => ParameterType::INTEGER,
                'total_minor' => ParameterType::INTEGER,
            ],
        );
        $this->orderIds[] = $orderId;

        $connection->insert(
            'ordering_order_lines',
            [
                'id' => Uuid::v4()->toRfc4122(),
                'order_id' => $orderId,
                'product_slug' => 'Line of '.$orderNumber,
                'product_name' => 'Product '.$orderNumber,
                'variant_label' => null,
                'unit_price_minor' => 10_000,
                'currency' => 'TND',
                'quantity' => 1,
            ],
            ['unit_price_minor' => ParameterType::INTEGER, 'quantity' => ParameterType::INTEGER],
        );
    }

    private static function connection(): Connection
    {
        $connection = static::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);

        return $connection;
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
