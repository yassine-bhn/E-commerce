<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class AdminProxyOrderStockTest extends WebTestCase
{
    private const string ADMIN_ID = '01929312-7000-7000-8000-000000000098';
    private const string ADMIN_EMAIL = 'proxy.stock@example.com';

    private ?string $slug = null;
    private ?string $orderId = null;

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
        $connection->executeStatement('DELETE FROM customer_profile WHERE id = :id', ['id' => self::ADMIN_ID]);

        parent::tearDown();
    }

    public function testProxyOrderReservesTrackedStock(): void
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

        self::assertSame(3, (int) $connection->fetchOne(
            'SELECT stock_quantity FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $this->slug],
        ));
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM inventory_reservation WHERE order_id = :id AND product_slug = :slug',
            ['id' => $this->orderId, 'slug' => $this->slug],
        ));
    }

    public function testProxyOrderRejectsANonPositiveLineQuantity(): void
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
            'lines' => [['slug' => $this->slug, 'quantity' => -2]],
            'deliveryMethod' => 'home',
            'paymentMethod' => 'Virement / Paiement à la livraison',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(5, $this->stock($connection), 'a rejected line must not move stock');
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM ordering_orders WHERE order_source = :source AND customer_phone = :phone',
            ['source' => 'WHATSAPP', 'phone' => '+216 20 000 000'],
        ));
    }

    public function testProxyOrderRejectsALineWhoseSlugIsNotAString(): void
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
            'lines' => [['slug' => [$this->slug], 'quantity' => 1]],
            'deliveryMethod' => 'home',
            'paymentMethod' => 'Virement / Paiement à la livraison',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(5, $this->stock($connection), 'a rejected line must not move stock');
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
        $this->slug = 'test-proxy-stock-'.substr(Uuid::v4()->toRfc4122(), 0, 8);
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

    private function seedAdmin(Connection $connection): void
    {
        $connection->insert('customer_profile', [
            'id' => self::ADMIN_ID,
            'name' => 'Proxy Stock Admin',
            'email' => self::ADMIN_EMAIL,
            'role' => 'ROLE_ADMIN',
            'status' => 'active',
        ]);
    }

    private function stock(Connection $connection): int
    {
        return (int) $connection->fetchOne(
            'SELECT stock_quantity FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $this->slug],
        );
    }
}
