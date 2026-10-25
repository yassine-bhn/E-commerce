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

final class CheckoutPaymentMethodTest extends WebTestCase
{
    private const string CUSTOMER_ID = '01929312-7000-7000-8000-000000000090';
    private const string CUSTOMER_EMAIL = 'payment.method@example.com';
    private const string SUPER_ADMIN_ID = '01929312-7000-7000-8000-000000000091';
    private const string SUPER_ADMIN_EMAIL = 'payment.method.admin@example.com';

    /** Mirrors the migration default; tearDown puts the singleton back. */
    private const string DEFAULT_METHODS = '["Carte bancaire","Virement / Paiement à la livraison","Espèces à la livraison"]';

    private ?string $slug = null;
    private ?string $orderId = null;

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
            'admin' => self::SUPER_ADMIN_ID,
        ]);
        $connection->executeStatement(
            "UPDATE platform_setting
             SET contact_phone = '', contact_whatsapp = '', auto_cancel_minutes = 30, payment_methods = :methods, updated_at = NOW()
             WHERE id = 1",
            ['methods' => self::DEFAULT_METHODS],
        );

        parent::tearDown();
    }

    public function testAMethodOutsideTheConfiguredAllowListIsRefused(): void
    {
        $client = $this->loginAsCustomer();

        $this->checkout($client, 'Bitcoin');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('payment-method-not-allowed', $body['error']);

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM ordering_orders WHERE customer_id = :id',
            ['id' => self::CUSTOMER_ID],
        ), 'a refused method must not reserve stock or create an order');
    }

    public function testTheAllowListIsWhatTheOperatorConfigured(): void
    {
        $client = $this->loginAsCustomer();

        $this->checkout($client, 'Carte bancaire');
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $client->loginUser(new CustomerUser(self::SUPER_ADMIN_ID, self::SUPER_ADMIN_EMAIL, null, 'ROLE_SUPER_ADMIN', 'active'));
        $client->jsonRequest('PUT', '/api/v1/admin/settings', [
            'contactPhone' => '+21671000000',
            'contactWhatsapp' => '+21620000000',
            'autoCancelMinutes' => 30,
            'paymentMethods' => ['Carte bancaire'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        /** @var array<string, mixed> $settings */
        $settings = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['Carte bancaire'], $settings['paymentMethods'], 'the operator sees what they configured');

        // The same client keeps the customer session only through a re-login; the allow-list
        // is global, so a second customer-shaped checkout proves the removal took effect.
        $client->loginUser(new CustomerUser(self::CUSTOMER_ID, self::CUSTOMER_EMAIL, null, 'ROLE_USER', 'active'));
        $this->checkout($client, 'Espèces à la livraison');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        self::assertSame('["Carte bancaire"]', $connection->fetchOne(
            'SELECT payment_methods FROM platform_setting WHERE id = 1',
        ));
    }

    private function loginAsCustomer(): KernelBrowser
    {
        $client = static::createClient();
        $this->resetRateLimiter();

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $this->seedProduct($connection);
        $this->seedAccounts($connection);
        $client->loginUser(new CustomerUser(self::CUSTOMER_ID, self::CUSTOMER_EMAIL, null, 'ROLE_USER', 'active'));

        return $client;
    }

    private function checkout(KernelBrowser $client, string $method): void
    {
        $client->jsonRequest('POST', '/api/v1/storefront/orders', [
            'lines' => [['slug' => $this->slug, 'quantity' => 1]],
            'deliveryMethod' => 'home',
            'paymentMethod' => $method,
        ], ['HTTP_IDEMPOTENCY_KEY' => 'payment-method-'.Uuid::v4()->toRfc4122()]);

        /** @var array<string, mixed>|null $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        if (is_array($body) && isset($body['orderId']) && null === $this->orderId) {
            $this->orderId = (string) $body['orderId'];
        }
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
        $this->slug = 'test-payment-method-'.substr(Uuid::v4()->toRfc4122(), 0, 8);
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

    private function seedAccounts(Connection $connection): void
    {
        $connection->executeStatement(
            'INSERT INTO customer_profile (id, name, email, role, status)
             VALUES (:customer, :customer_name, :customer_email, :customer_role, :status)
             ON CONFLICT DO NOTHING',
            [
                'customer' => self::CUSTOMER_ID,
                'customer_name' => 'Payment Method Customer',
                'customer_email' => self::CUSTOMER_EMAIL,
                'customer_role' => 'ROLE_USER',
                'status' => 'active',
            ],
        );
        $connection->executeStatement(
            'INSERT INTO customer_profile (id, name, email, role, status)
             VALUES (:admin, :admin_name, :admin_email, :admin_role, :status)
             ON CONFLICT DO NOTHING',
            [
                'admin' => self::SUPER_ADMIN_ID,
                'admin_name' => 'Payment Method Admin',
                'admin_email' => self::SUPER_ADMIN_EMAIL,
                'admin_role' => 'ROLE_SUPER_ADMIN',
                'status' => 'active',
            ],
        );
    }
}
