<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class AdminPaymentReadTest extends WebTestCase
{
    private const string CUSTOMER_ID = '01929312-7000-7000-8000-000000000086';
    private const string CUSTOMER_EMAIL = 'payment.read@example.com';
    private const string ADMIN_ID = '01929312-7000-7000-8000-000000000087';
    private const string ADMIN_EMAIL = 'payment.read.admin@example.com';

    private ?string $slug = null;
    private ?string $orderId = null;
    private ?string $paymentId = null;

    protected function tearDown(): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        if (null !== $this->orderId) {
            $connection->executeStatement(
                'DELETE FROM payment_ledger WHERE payment_id IN (SELECT id FROM payment WHERE order_id = :id)',
                ['id' => $this->orderId],
            );
            $connection->executeStatement('DELETE FROM payment WHERE order_id = :id', ['id' => $this->orderId]);
            $connection->executeStatement('DELETE FROM outbox WHERE aggregate_id IN (:order, :payment)', [
                'order' => $this->orderId,
                'payment' => $this->paymentId ?? '',
            ]);
            $connection->executeStatement('DELETE FROM inventory_reservation WHERE order_id = :id', ['id' => $this->orderId]);
            $connection->executeStatement('DELETE FROM ordering_order_lines WHERE order_id = :id', ['id' => $this->orderId]);
            $connection->executeStatement('DELETE FROM ordering_order_audit_trail WHERE order_id = :id', ['id' => $this->orderId]);
            $connection->executeStatement('DELETE FROM ordering_orders WHERE id = :id', ['id' => $this->orderId]);
        }
        if (null !== $this->slug) {
            $connection->executeStatement('DELETE FROM storefront_product_view WHERE slug = :slug', ['slug' => $this->slug]);
        }
        $connection->executeStatement('DELETE FROM customer_profile WHERE id IN (:customer, :admin)', [
            'customer' => self::CUSTOMER_ID,
            'admin' => self::ADMIN_ID,
        ]);

        parent::tearDown();
    }

    public function testAdminReadsThePaymentWithItsLedger(): void
    {
        $client = $this->placeAndPayOrder();

        $client->jsonRequest('GET', '/api/v1/admin/payments/'.$this->paymentId);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($this->paymentId, $body['paymentId']);
        self::assertSame($this->orderId, $body['orderId']);
        self::assertSame('Carte bancaire', $body['method']);
        self::assertSame('authorized', $body['status']);
        self::assertSame(2, $body['version']);
        self::assertSame('TND', $body['currency']);
        self::assertIsInt($body['amountMinor']);
        self::assertGreaterThan(0, $body['amountMinor']);

        self::assertIsArray($body['ledger']);
        self::assertCount(2, $body['ledger'], 'the admin sees the whole history, not just the last state');
        self::assertSame('pending', $body['ledger'][0]['toStatus']);
        self::assertNull($body['ledger'][0]['fromStatus']);
        self::assertSame('pending', $body['ledger'][1]['fromStatus']);
        self::assertSame('authorized', $body['ledger'][1]['toStatus']);
        self::assertSame(self::ADMIN_ID, $body['ledger'][1]['actorId']);
    }

    public function testACustomerCannotReadThePayment(): void
    {
        $client = $this->placeAndPayOrder();

        $client->loginUser(new CustomerUser(self::CUSTOMER_ID, self::CUSTOMER_EMAIL, null, 'ROLE_USER', 'active'));
        $client->jsonRequest('GET', '/api/v1/admin/payments/'.$this->paymentId);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAnUnknownPaymentIsNotFound(): void
    {
        $client = $this->placeAndPayOrder();

        $client->jsonRequest('GET', '/api/v1/admin/payments/'.Uuid::v4()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function placeAndPayOrder(): KernelBrowser
    {
        $client = static::createClient();
        $this->resetRateLimiter();

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $this->seedProduct($connection);
        $this->seedAccounts($connection);

        $client->loginUser(new CustomerUser(self::CUSTOMER_ID, self::CUSTOMER_EMAIL, null, 'ROLE_USER', 'active'));
        $client->jsonRequest('POST', '/api/v1/storefront/orders', [
            'lines' => [['slug' => $this->slug, 'quantity' => 1]],
            'deliveryMethod' => 'home',
            'paymentMethod' => 'Carte bancaire',
        ], ['HTTP_IDEMPOTENCY_KEY' => 'payment-read-'.Uuid::v4()->toRfc4122()]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        /** @var array<string, mixed> $created */
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->orderId = (string) $created['orderId'];
        $this->paymentId = (string) $connection->fetchOne(
            'SELECT id FROM payment WHERE order_id = :order',
            ['order' => $this->orderId],
        );

        $client->loginUser(new CustomerUser(self::ADMIN_ID, self::ADMIN_EMAIL, null, 'ROLE_ADMIN', 'active'));
        $client->jsonRequest('POST', '/api/v1/admin/payments/'.$this->paymentId.'/confirm', [
            'expectedVersion' => 1,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        return $client;
    }

    /**
     * TDD-005 — the admin limiter is keyed by client IP, so every admin test shares one bucket.
     * The pool is cleared after the kernel exists, because WebTestCase forbids booting it before
     * createClient(); this mirrors RateLimitingTest.
     */
    private function resetRateLimiter(): void
    {
        $container = static::getContainer();
        if ($container->has('cache.rate_limiter')) {
            /** @var CacheItemPoolInterface $pool */
            $pool = $container->get('cache.rate_limiter');
            $pool->clear();
        }
    }

    private function seedProduct(Connection $connection): void
    {
        $this->slug = 'test-payment-read-'.substr(Uuid::v4()->toRfc4122(), 0, 8);
        $connection->executeStatement(
            'INSERT INTO storefront_product_view (
                id, slug, name, brand, ref, category_slug, subcategory, price_minor, old_price_minor, currency,
                stock, stock_quantity, material, dims, image_path, rating, reviews, is_new, is_best, headrest, description, variants
            )
            SELECT :id, :slug, name, brand, ref, category_slug, subcategory, price_minor, old_price_minor, currency,
                   stock, 5, material, dims, image_path, rating, reviews, is_new, is_best, headrest, description, variants
            FROM storefront_product_view
            WHERE slug = :source',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'slug' => $this->slug,
                'source' => 'baignoire-d-code',
            ],
        );
    }

    private function seedAccounts(Connection $connection): void
    {
        $connection->executeStatement(
            'INSERT INTO customer_profile (id, name, email, role, status)
             VALUES (:customer, :name, :email, :role, :status)
             ON CONFLICT DO NOTHING',
            [
                'customer' => self::CUSTOMER_ID,
                'name' => 'Payment Read Customer',
                'email' => self::CUSTOMER_EMAIL,
                'role' => 'ROLE_USER',
                'status' => 'active',
            ],
        );
        $connection->executeStatement(
            'INSERT INTO customer_profile (id, name, email, role, status)
             VALUES (:admin, :admin_name, :admin_email, :admin_role, :status)
             ON CONFLICT DO NOTHING',
            [
                'admin' => self::ADMIN_ID,
                'admin_name' => 'Payment Read Admin',
                'admin_email' => self::ADMIN_EMAIL,
                'admin_role' => 'ROLE_ADMIN',
                'status' => 'active',
            ],
        );
    }
}
