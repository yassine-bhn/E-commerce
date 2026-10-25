<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class CustomerCancelOrderTest extends WebTestCase
{
    private const string CUSTOMER_ID = '01929312-7000-7000-8000-000000000097';
    private const string CUSTOMER_EMAIL = 'cancel.order@example.com';

    private ?string $slug = null;
    private ?string $orderId = null;
    private ?string $otherCustomerId = null;

    protected function tearDown(): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        if (null !== $this->orderId) {
            $connection->executeStatement('DELETE FROM inventory_reservation WHERE order_id = :id', ['id' => $this->orderId]);
            $connection->executeStatement('DELETE FROM ordering_order_lines WHERE order_id = :id', ['id' => $this->orderId]);
            $connection->executeStatement('DELETE FROM ordering_order_audit_trail WHERE order_id = :id', ['id' => $this->orderId]);
            $connection->executeStatement('DELETE FROM ordering_orders WHERE id = :id', ['id' => $this->orderId]);
        }
        if (null !== $this->slug) {
            $connection->executeStatement('DELETE FROM storefront_product_view WHERE slug = :slug', ['slug' => $this->slug]);
        }
        $connection->executeStatement('DELETE FROM customer_profile WHERE id = :id', ['id' => self::CUSTOMER_ID]);
        if (null !== $this->otherCustomerId) {
            $connection->executeStatement('DELETE FROM customer_profile WHERE id = :id', ['id' => $this->otherCustomerId]);
        }

        parent::tearDown();
    }

    public function testCustomerCancellationReleasesStockAndRecordsTransition(): void
    {
        $client = static::createClient();
        $connection = $this->prepareOrder($client);

        $client->jsonRequest('POST', '/api/v1/storefront/orders/'.$this->orderId.'/cancel', ['expectedVersion' => 1]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        self::assertSame(5, (int) $connection->fetchOne(
            'SELECT stock_quantity FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $this->slug],
        ));
        self::assertSame('released', $connection->fetchOne(
            'SELECT status FROM inventory_reservation WHERE order_id = :id',
            ['id' => $this->orderId],
        ));
        self::assertSame(2, (int) $connection->fetchOne(
            'SELECT version FROM ordering_orders WHERE id = :id',
            ['id' => $this->orderId],
        ));
        self::assertSame('已取消', $connection->fetchOne(
            'SELECT status FROM ordering_orders WHERE id = :id',
            ['id' => $this->orderId],
        ));
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM ordering_order_audit_trail WHERE order_id = :id AND reason = :reason',
            ['id' => $this->orderId, 'reason' => 'Customer cancellation'],
        ));
    }

    public function testCustomerCannotCancelAnotherCustomersOrder(): void
    {
        $client = static::createClient();
        $connection = $this->prepareOrder($client);

        $this->otherCustomerId = '01929312-7000-7000-8000-000000000096';
        $connection->insert('customer_profile', [
            'id' => $this->otherCustomerId,
            'name' => 'Other Customer',
            'email' => 'other.cancel@example.com',
            'role' => 'ROLE_USER',
            'status' => 'active',
        ]);
        $client->loginUser(new CustomerUser($this->otherCustomerId, 'other.cancel@example.com', null, 'ROLE_USER', 'active'));

        $client->jsonRequest('POST', '/api/v1/storefront/orders/'.$this->orderId.'/cancel', ['expectedVersion' => 1]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        self::assertSame(3, (int) $connection->fetchOne(
            'SELECT stock_quantity FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $this->slug],
        ));
        self::assertSame('reserved', $connection->fetchOne(
            'SELECT status FROM inventory_reservation WHERE order_id = :id',
            ['id' => $this->orderId],
        ));
        self::assertSame('待支付', $connection->fetchOne(
            'SELECT status FROM ordering_orders WHERE id = :id',
            ['id' => $this->orderId],
        ));
    }

    public function testCustomerCannotCancelNonPendingOrder(): void
    {
        $client = static::createClient();
        $connection = $this->prepareOrder($client);

        $connection->executeStatement(
            'UPDATE ordering_orders SET status = :status WHERE id = :id',
            ['status' => '已支付', 'id' => $this->orderId],
        );

        $client->jsonRequest('POST', '/api/v1/storefront/orders/'.$this->orderId.'/cancel', ['expectedVersion' => 1]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        self::assertSame(3, (int) $connection->fetchOne(
            'SELECT stock_quantity FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $this->slug],
        ));
        self::assertSame('reserved', $connection->fetchOne(
            'SELECT status FROM inventory_reservation WHERE order_id = :id',
            ['id' => $this->orderId],
        ));
    }

    /**
     * Creates a pending online order for 2 units through the checkout endpoint (stock 5 → 3).
     */
    private function prepareOrder(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): Connection
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $this->slug = 'test-cancel-stock-'.substr(Uuid::v4()->toRfc4122(), 0, 8);
        $connection->executeStatement(
            'INSERT INTO storefront_product_view (
                id, slug, name, brand, ref, category_slug, subcategory, price_minor, old_price_minor, currency,
                stock, stock_quantity, material, dims, image_path, rating, reviews, is_new, is_best, headrest, description, variants, approval_status, published
            )
            SELECT :id, :slug, name, brand, ref, category_slug, subcategory, price_minor, old_price_minor, currency,
                   stock, 5, material, dims, image_path, rating, reviews, is_new, is_best, headrest, description, variants, approval_status, published
            FROM storefront_product_view
            WHERE slug = :source',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'slug' => $this->slug,
                'source' => 'baignoire-d-code',
            ],
        );

        $connection->executeStatement(
            'INSERT INTO customer_profile (id, name, email, role, status)
             VALUES (:id, :name, :email, :role, :status)
             ON CONFLICT DO NOTHING',
            [
                'id' => self::CUSTOMER_ID,
                'name' => 'Cancel Order Test',
                'email' => self::CUSTOMER_EMAIL,
                'role' => 'ROLE_USER',
                'status' => 'active',
            ],
        );
        $client->loginUser(new CustomerUser(self::CUSTOMER_ID, self::CUSTOMER_EMAIL, null, 'ROLE_USER', 'active'));

        $client->jsonRequest('POST', '/api/v1/storefront/orders', [
            'lines' => [['slug' => $this->slug, 'quantity' => 2]],
            'deliveryMethod' => 'home',
            'paymentMethod' => 'Carte bancaire',
        ], ['HTTP_IDEMPOTENCY_KEY' => 'cancel-order-test-'.Uuid::v4()->toRfc4122()]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        /** @var array<string, mixed> $created */
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->orderId = (string) $created['orderId'];

        return $connection;
    }
}
