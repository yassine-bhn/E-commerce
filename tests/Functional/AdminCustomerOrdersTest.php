<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * WP4 / R16 + R24 — GET /api/v1/admin/customers/{id}/orders for the admin dashboard:
 * that user's orders newest-first with integer minor-unit totals and line items, in the
 * {items: [...]} envelope the admin surface already uses.
 */
final class AdminCustomerOrdersTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'wp4.admin@example.com';
    private const CUSTOMER_EMAIL = 'wp4.customer@example.com';
    private const OTHER_EMAIL = 'wp4.other@example.com';
    private const EMPTY_EMAIL = 'wp4.empty@example.com';
    private const PLAIN_EMAIL = 'wp4.plain@example.com';

    private const FIXTURE_ORDER_NUMBERS = ['ZG-FUNC-OLD', 'ZG-FUNC-NEW', 'ZG-FUNC-OTHER', 'ZG-FUNC-PROXY'];

    /** @var list<string> */
    private array $orderIds = [];

    /** @var list<string> */
    private array $customerIds = [];

    protected function tearDown(): void
    {
        $connection = $this->connection();

        if ([] !== $this->orderIds) {
            $connection->executeStatement('DELETE FROM ordering_order_lines WHERE order_id IN (:ids)', ['ids' => $this->orderIds], ['ids' => ArrayParameterType::STRING]);
            $connection->executeStatement('DELETE FROM ordering_orders WHERE id IN (:ids)', ['ids' => $this->orderIds], ['ids' => ArrayParameterType::STRING]);
        }

        if ([] !== $this->customerIds) {
            $connection->executeStatement('DELETE FROM customer_profile WHERE id IN (:ids)', ['ids' => $this->customerIds], ['ids' => ArrayParameterType::STRING]);
        }

        parent::tearDown();
    }

    public function testAnAdminReadsThatCustomersOrdersNewestFirstWithMinorUnitTotalsAndLines(): void
    {
        $client = $this->browser();
        $client->loginUser($this->createAuthenticatedUser(self::ADMIN_EMAIL, 'Dashboard Admin', 'ROLE_SUPER_ADMIN'));

        $customerId = $this->createProfile(self::CUSTOMER_EMAIL, 'Ordering Customer', 'ROLE_USER');
        $older = $this->seedOrder($customerId, 'ZG-FUNC-OLD', '2026-02-01 10:00:00+01', 7_000, 0, 1_500, 8_500);
        $newer = $this->seedOrder($customerId, 'ZG-FUNC-NEW', '2026-05-01 10:00:00+01', 20_000, 2_500, 0, 17_500);
        $this->seedLine($older, 'baignoire-d-code', 'Baignoire D Code', '80 L', 7_000, 1);
        $this->seedLine($newer, 'wc-suspendu', 'WC Suspendu', null, 10_000, 2);

        $client->request('GET', '/api/v1/admin/customers/'.$customerId.'/orders');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $items = $this->json($client)['items'] ?? null;
        self::assertIsArray($items);
        self::assertSame(['ZG-FUNC-NEW', 'ZG-FUNC-OLD'], array_column($items, 'orderNumber'));
        self::assertSame(20_000, $items[0]['subtotal']);
        self::assertSame(2_500, $items[0]['discount']);
        self::assertSame(17_500, $items[0]['total']);
        self::assertSame('TND', $items[0]['currency']);
        self::assertSame('2026-05-01', $items[0]['date']);
        self::assertSame('wc-suspendu', $items[0]['lines'][0]['slug']);
        self::assertSame(10_000, $items[0]['lines'][0]['price']);
        self::assertSame(2, $items[0]['lines'][0]['qty']);
        self::assertSame(7_000, $items[1]['lines'][0]['price']);
    }

    public function testACustomerWithoutOrdersIsAnEmptyListAndNotAFourOhFour(): void
    {
        $client = $this->browser();
        $client->loginUser($this->createAuthenticatedUser(self::ADMIN_EMAIL, 'Dashboard Admin', 'ROLE_SUPER_ADMIN'));

        $customerId = $this->createProfile(self::EMPTY_EMAIL, 'Customer Without Orders', 'ROLE_USER');

        $client->request('GET', '/api/v1/admin/customers/'.$customerId.'/orders');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['items' => []], $this->json($client));
    }

    public function testAnUnknownCustomerIdIsNotFound(): void
    {
        $client = $this->browser();
        $client->loginUser($this->createAuthenticatedUser(self::ADMIN_EMAIL, 'Dashboard Admin', 'ROLE_SUPER_ADMIN'));

        $client->request('GET', '/api/v1/admin/customers/'.Uuid::v4()->toRfc4122().'/orders');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAMalformedCustomerIdIsRefusedAndNeverAServerError(): void
    {
        $client = $this->browser();
        $client->loginUser($this->createAuthenticatedUser(self::ADMIN_EMAIL, 'Dashboard Admin', 'ROLE_SUPER_ADMIN'));

        foreach (['not-a-uuid', '00000000-0000-0000-0000-00000000000', '1;DROP TABLE ordering_orders', 'null'] as $id) {
            $client->request('GET', '/api/v1/admin/customers/'.rawurlencode($id).'/orders');
            self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST, 'Expected a clean refusal of the malformed id "'.$id.'"');
            self::assertArrayHasKey('error', $this->json($client));
        }

        // An empty id collapses the path segment and never reaches the handler; it must still be
        // a client error rather than a server error.
        $client->request('GET', '/api/v1/admin/customers//orders');
        self::assertTrue($client->getResponse()->isClientError(), 'Expected a 4xx for a blank id, got '.$client->getResponse()->getStatusCode());
    }

    public function testAGuestIsNotAuthenticatedOnThisRoute(): void
    {
        $client = $this->browser();
        $customerId = $this->createProfile(self::CUSTOMER_EMAIL, 'Ordering Customer', 'ROLE_USER');

        $client->request('GET', '/api/v1/admin/customers/'.$customerId.'/orders');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAPlainCustomerIsForbiddenOnThisRoute(): void
    {
        $client = $this->browser();
        $customerId = $this->createProfile(self::CUSTOMER_EMAIL, 'Ordering Customer', 'ROLE_USER');
        $client->loginUser($this->createAuthenticatedUser(self::PLAIN_EMAIL, 'Plain Customer', 'ROLE_USER'));

        $client->request('GET', '/api/v1/admin/customers/'.$customerId.'/orders');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAStandardAdminReadsTheOrdersOfTheCustomer(): void
    {
        $client = $this->browser();
        $client->loginUser($this->createAuthenticatedUser(self::ADMIN_EMAIL, 'Standard Admin', 'ROLE_ADMIN'));

        $customerId = $this->createProfile(self::CUSTOMER_EMAIL, 'Ordering Customer', 'ROLE_USER');
        $this->seedOrder($customerId, 'ZG-FUNC-NEW', '2026-05-01 10:00:00+01', 20_000, 0, 0, 20_000);

        $client->request('GET', '/api/v1/admin/customers/'.$customerId.'/orders');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['ZG-FUNC-NEW'], array_column($this->json($client)['items'], 'orderNumber'));
    }

    public function testAnotherCustomersOrderIsNeverReturned(): void
    {
        $client = $this->browser();
        $client->loginUser($this->createAuthenticatedUser(self::ADMIN_EMAIL, 'Dashboard Admin', 'ROLE_SUPER_ADMIN'));

        $customerId = $this->createProfile(self::CUSTOMER_EMAIL, 'Ordering Customer', 'ROLE_USER');
        $otherId = $this->createProfile(self::OTHER_EMAIL, 'Other Customer', 'ROLE_USER');
        $this->seedOrder($customerId, 'ZG-FUNC-NEW', '2026-05-01 10:00:00+01', 20_000, 0, 0, 20_000);
        $this->seedOrder($otherId, 'ZG-FUNC-OTHER', '2026-06-01 10:00:00+01', 33_300, 0, 0, 33_300);

        $client->request('GET', '/api/v1/admin/customers/'.$customerId.'/orders');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $items = $this->json($client)['items'];
        self::assertSame(['ZG-FUNC-NEW'], array_column($items, 'orderNumber'));
        self::assertStringNotContainsString('ZG-FUNC-OTHER', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString($otherId, (string) $client->getResponse()->getContent());
        self::assertSame($customerId, $this->customerOfOrder('ZG-FUNC-NEW'));
    }

    public function testAPhoneOrWhatsappProxyOrderOfTheSameCustomerIsNotInThisList(): void
    {
        $client = $this->browser();
        $client->loginUser($this->createAuthenticatedUser(self::ADMIN_EMAIL, 'Dashboard Admin', 'ROLE_SUPER_ADMIN'));

        $customerId = $this->createProfile(self::CUSTOMER_EMAIL, 'Ordering Customer', 'ROLE_USER');
        $this->seedOrder($customerId, 'ZG-FUNC-NEW', '2026-05-01 10:00:00+01', 20_000, 0, 0, 20_000);
        $this->seedProxyOrder($customerId, 'ZG-FUNC-PROXY', '2026-09-01 10:00:00+01');

        $client->request('GET', '/api/v1/admin/customers/'.$customerId.'/orders');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['ZG-FUNC-NEW'], array_column($this->json($client)['items'], 'orderNumber'));
    }

    private function browser(): KernelBrowser
    {
        $client = static::createClient();
        $this->purgeFixtures();

        return $client;
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
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
            ['numbers' => self::FIXTURE_ORDER_NUMBERS],
            ['numbers' => ArrayParameterType::STRING],
        );

        if ([] !== $ids) {
            $connection->executeStatement('DELETE FROM ordering_order_lines WHERE order_id IN (:ids)', ['ids' => $ids], ['ids' => ArrayParameterType::STRING]);
            $connection->executeStatement('DELETE FROM ordering_orders WHERE id IN (:ids)', ['ids' => $ids], ['ids' => ArrayParameterType::STRING]);
        }

        /** @var list<string> $profiles */
        $profiles = $connection->fetchFirstColumn('SELECT id FROM customer_profile WHERE email IN (:emails)', [
            'emails' => [self::ADMIN_EMAIL, self::CUSTOMER_EMAIL, self::OTHER_EMAIL, self::EMPTY_EMAIL, self::PLAIN_EMAIL],
        ], ['emails' => ArrayParameterType::STRING]);

        if ([] !== $profiles) {
            $connection->executeStatement('DELETE FROM customer_profile WHERE id IN (:ids)', ['ids' => $profiles], ['ids' => ArrayParameterType::STRING]);
        }
    }

    private function createProfile(string $email, string $name, string $role): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->customerIds[] = $id;
        $this->connection()->insert('customer_profile', [
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'status' => 'active',
        ]);

        return $id;
    }

    private function createAuthenticatedUser(string $email, string $name, string $role): CustomerUser
    {
        return new CustomerUser($this->createProfile($email, $name, $role), $email, null, $role, 'active');
    }

    private function seedProxyOrder(string $customerId, string $orderNumber, string $placedAt): string
    {
        $id = $this->seedOrder($customerId, $orderNumber, $placedAt, 1_000, 0, 0, 1_000);
        $this->connection()->update('ordering_orders', [
            'order_source' => 'WHATSAPP',
            'customer_name' => 'Guest By WhatsApp',
            'customer_phone' => '+216 00 000 000',
            'created_by_admin_id' => $customerId,
        ], ['id' => $id]);

        return $id;
    }

    private function customerOfOrder(string $orderNumber): string
    {
        $customer = $this->connection()->fetchOne(
            'SELECT customer_id::text FROM ordering_orders WHERE order_number = :number',
            ['number' => $orderNumber],
        );

        return (string) $customer;
    }

    private function seedOrder(string $customerId, string $orderNumber, string $placedAt, int $subtotal, int $discount, int $delivery, int $total): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->orderIds[] = $id;

        $this->connection()->insert('ordering_orders', [
            'id' => $id,
            'customer_id' => $customerId,
            'status' => '待支付',
            'version' => 1,
            'order_number' => $orderNumber,
            'order_source' => 'ONLINE',
            'subtotal_minor' => $subtotal,
            'discount_minor' => $discount,
            'delivery_minor' => $delivery,
            'total_minor' => $total,
            'currency' => 'TND',
            'delivery_method' => 'home',
            'payment_method' => 'Carte bancaire',
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
