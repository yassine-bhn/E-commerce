<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class ConfirmOfflinePaymentTest extends WebTestCase
{
    private const string CUSTOMER_ID = '01929312-7000-7000-8000-000000000094';
    private const string CUSTOMER_EMAIL = 'confirm.payment@example.com';
    private const string ADMIN_ID = '01929312-7000-7000-8000-000000000095';
    private const string ADMIN_EMAIL = 'confirm.payment.admin@example.com';

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

    public function testAdminConfirmsAnOfflineReceipt(): void
    {
        $client = $this->placeOrderAsCustomer();

        $client->loginUser(new CustomerUser(self::ADMIN_ID, self::ADMIN_EMAIL, null, 'ROLE_ADMIN', 'active'));
        $client->jsonRequest('POST', '/api/v1/admin/payments/'.$this->paymentId.'/confirm', [
            'expectedVersion' => 1,
            'reference' => 'VIR-2026-00042',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $payment = $connection->fetchAssociative(
            'SELECT status, version, amount_minor FROM payment WHERE id = :id',
            ['id' => $this->paymentId],
        );
        self::assertIsArray($payment);
        self::assertSame('authorized', $payment['status']);
        self::assertSame(2, (int) $payment['version'], 'RP1.1 — every payment movement increments its version');

        $ledger = $connection->fetchAllAssociative(
            'SELECT from_status, to_status, version, amount_minor, actor_id FROM payment_ledger WHERE payment_id = :id ORDER BY version',
            ['id' => $this->paymentId],
        );
        self::assertCount(2, $ledger, 'the confirmation appends one row to the opening row');
        self::assertSame('pending', $ledger[1]['from_status']);
        self::assertSame('authorized', $ledger[1]['to_status']);
        self::assertSame(2, (int) $ledger[1]['version']);
        self::assertSame((int) $payment['amount_minor'], (int) $ledger[1]['amount_minor']);
        self::assertSame(self::ADMIN_ID, $ledger[1]['actor_id']);

        $order = $connection->fetchAssociative(
            'SELECT status, version FROM ordering_orders WHERE id = :id',
            ['id' => $this->orderId],
        );
        self::assertIsArray($order);
        self::assertSame('已支付', $order['status'], 'a confirmed receipt pays the order');
        self::assertSame(2, (int) $order['version']);

        $audit = $connection->fetchAssociative(
            'SELECT from_status, to_status, from_version, to_version, changed_by FROM ordering_order_audit_trail WHERE order_id = :id ORDER BY to_version',
            ['id' => $this->orderId],
        );
        self::assertIsArray($audit);
        self::assertSame('待支付', $audit['from_status']);
        self::assertSame('已支付', $audit['to_status']);
        self::assertSame(1, (int) $audit['from_version']);
        self::assertSame(2, (int) $audit['to_version']);
        self::assertSame(self::ADMIN_ID, $audit['changed_by'], 'RP1.6 — the order trail names the admin');

        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM outbox WHERE aggregate_id = :id AND event_type = :type',
            ['id' => $this->paymentId, 'type' => 'App\Payment\Domain\Event\PaymentAuthorized'],
        ), 'ADR-0001 — Ordering learns about the authorization through the outbox');
    }

    public function testAStaleExpectedVersionIsRefusedAndWritesNothing(): void
    {
        $client = $this->placeOrderAsCustomer();

        $client->loginUser(new CustomerUser(self::ADMIN_ID, self::ADMIN_EMAIL, null, 'ROLE_ADMIN', 'active'));
        $client->jsonRequest('POST', '/api/v1/admin/payments/'.$this->paymentId.'/confirm', [
            'expectedVersion' => 7,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        self::assertSame('pending', $connection->fetchOne(
            'SELECT status FROM payment WHERE id = :id',
            ['id' => $this->paymentId],
        ), 'RP1.2 — a stale caller must not write');
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT version FROM payment WHERE id = :id',
            ['id' => $this->paymentId],
        ));
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM payment_ledger WHERE payment_id = :id',
            ['id' => $this->paymentId],
        ));
        self::assertSame('待支付', $connection->fetchOne(
            'SELECT status FROM ordering_orders WHERE id = :id',
            ['id' => $this->orderId],
        ));
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT version FROM ordering_orders WHERE id = :id',
            ['id' => $this->orderId],
        ));
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM outbox WHERE aggregate_id = :id',
            ['id' => $this->paymentId],
        ));
    }

    public function testConfirmingTheSameReceiptTwiceIsRefusedAndKeepsOneLedgerEntry(): void
    {
        $client = $this->placeOrderAsCustomer();

        $client->loginUser(new CustomerUser(self::ADMIN_ID, self::ADMIN_EMAIL, null, 'ROLE_ADMIN', 'active'));
        $client->jsonRequest('POST', '/api/v1/admin/payments/'.$this->paymentId.'/confirm', [
            'expectedVersion' => 1,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/admin/payments/'.$this->paymentId.'/confirm', [
            'expectedVersion' => 2,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('payment-not-confirmable', $body['error'], 'ECO-005 — the payment names its own state in the refusal');

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM payment_ledger WHERE payment_id = :id AND to_status = :status',
            ['id' => $this->paymentId, 'status' => 'authorized'],
        ), 'a receipt is authorized once, however many times the admin clicks');
        self::assertSame(2, (int) $connection->fetchOne(
            'SELECT version FROM payment WHERE id = :id',
            ['id' => $this->paymentId],
        ));
        self::assertSame(2, (int) $connection->fetchOne(
            'SELECT version FROM ordering_orders WHERE id = :id',
            ['id' => $this->orderId],
        ), 'the order is not paid twice either');
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM outbox WHERE aggregate_id = :id',
            ['id' => $this->paymentId],
        ));
    }

    private function placeOrderAsCustomer(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = static::createClient();
        $this->resetRateLimiter();

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $this->seedProduct($connection);
        $this->seedCustomer($connection);
        $this->seedAdmin($connection);
        $client->loginUser(new CustomerUser(self::CUSTOMER_ID, self::CUSTOMER_EMAIL, null, 'ROLE_USER', 'active'));

        $client->jsonRequest('POST', '/api/v1/storefront/orders', [
            'lines' => [['slug' => $this->slug, 'quantity' => 1]],
            'deliveryMethod' => 'home',
            'paymentMethod' => 'Carte bancaire',
        ], ['HTTP_IDEMPOTENCY_KEY' => 'confirm-payment-'.Uuid::v4()->toRfc4122()]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        /** @var array<string, mixed> $created */
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->orderId = (string) $created['orderId'];
        $this->paymentId = (string) $connection->fetchOne(
            'SELECT id FROM payment WHERE order_id = :order',
            ['order' => $this->orderId],
        );

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
        $this->slug = 'test-confirm-payment-'.substr(Uuid::v4()->toRfc4122(), 0, 8);
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
    }

    private function seedCustomer(Connection $connection): void
    {
        $connection->executeStatement(
            'INSERT INTO customer_profile (id, name, email, role, status)
             VALUES (:id, :name, :email, :role, :status)
             ON CONFLICT DO NOTHING',
            [
                'id' => self::CUSTOMER_ID,
                'name' => 'Confirm Payment Customer',
                'email' => self::CUSTOMER_EMAIL,
                'role' => 'ROLE_USER',
                'status' => 'active',
            ],
        );
    }

    private function seedAdmin(Connection $connection): void
    {
        $connection->executeStatement(
            'INSERT INTO customer_profile (id, name, email, role, status)
             VALUES (:id, :name, :email, :role, :status)
             ON CONFLICT DO NOTHING',
            [
                'id' => self::ADMIN_ID,
                'name' => 'Confirm Payment Admin',
                'email' => self::ADMIN_EMAIL,
                'role' => 'ROLE_ADMIN',
                'status' => 'active',
            ],
        );
    }
}
