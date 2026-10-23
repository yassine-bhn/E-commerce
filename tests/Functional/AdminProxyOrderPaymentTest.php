<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class AdminProxyOrderPaymentTest extends WebTestCase
{
    private const string ADMIN_ID = '01929312-7000-7000-8000-000000000099';
    private const string ADMIN_EMAIL = 'proxy.payment@example.com';

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
        $connection->executeStatement('DELETE FROM customer_profile WHERE id = :id', ['id' => self::ADMIN_ID]);

        parent::tearDown();
    }

    public function testProxyOrderCreatesAPendingPaymentForTheQuotedTotal(): void
    {
        $client = static::createClient();
        $this->resetRateLimiter();

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $this->seedProduct($connection);
        $this->seedAdmin($connection);
        $client->loginUser(new CustomerUser(self::ADMIN_ID, self::ADMIN_EMAIL, null, 'ROLE_ADMIN', 'active'));

        $client->jsonRequest('POST', '/api/v1/admin/orders/proxy', [
            'orderSource' => 'WHATSAPP',
            'customerName' => 'Client Proxy',
            'customerPhone' => '+216 20 000 000',
            'shippingAddress' => 'Tunis',
            'lines' => [['slug' => $this->slug, 'quantity' => 2]],
            'deliveryMethod' => 'home',
            'paymentMethod' => 'Virement / Paiement à la livraison',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        /** @var array<string, mixed> $created */
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->orderId = (string) $created['orderId'];

        $total = (int) $connection->fetchOne(
            'SELECT total_minor FROM ordering_orders WHERE id = :id',
            ['id' => $this->orderId],
        );
        $payment = $connection->fetchAssociative(
            'SELECT id, method, amount_minor, currency, status, version FROM payment WHERE order_id = :order',
            ['order' => $this->orderId],
        );

        self::assertIsArray($payment, 'a proxy order owes money too, so it opens a payment');
        self::assertSame('Virement / Paiement à la livraison', $payment['method']);
        self::assertSame($total, (int) $payment['amount_minor']);
        self::assertSame('TND', $payment['currency']);
        self::assertSame('pending', $payment['status']);
        self::assertSame(1, (int) $payment['version']);

        $ledger = $connection->fetchAllAssociative(
            'SELECT from_status, to_status, version, amount_minor, actor_id FROM payment_ledger WHERE payment_id = :id',
            ['id' => $payment['id']],
        );
        self::assertCount(1, $ledger);
        self::assertNull($ledger[0]['from_status']);
        self::assertSame('pending', $ledger[0]['to_status']);
        self::assertSame(1, (int) $ledger[0]['version']);
        self::assertSame($total, (int) $ledger[0]['amount_minor']);
        self::assertSame(self::ADMIN_ID, $ledger[0]['actor_id'], 'the ledger names the admin who opened the debt');
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
        $this->slug = 'test-proxy-payment-'.substr(Uuid::v4()->toRfc4122(), 0, 8);
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

    private function seedAdmin(Connection $connection): void
    {
        $connection->insert('customer_profile', [
            'id' => self::ADMIN_ID,
            'name' => 'Proxy Payment Admin',
            'email' => self::ADMIN_EMAIL,
            'role' => 'ROLE_ADMIN',
            'status' => 'active',
        ]);
    }
}
