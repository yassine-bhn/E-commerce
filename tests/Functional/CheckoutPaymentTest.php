<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class CheckoutPaymentTest extends WebTestCase
{
    private const string CUSTOMER_ID = '01929312-7000-7000-8000-000000000097';
    private const string CUSTOMER_EMAIL = 'checkout.payment@example.com';

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
            $connection->executeStatement('DELETE FROM outbox WHERE aggregate_id = :id', ['id' => $this->orderId]);
            $connection->executeStatement('DELETE FROM inventory_reservation WHERE order_id = :id', ['id' => $this->orderId]);
            $connection->executeStatement('DELETE FROM ordering_order_lines WHERE order_id = :id', ['id' => $this->orderId]);
            $connection->executeStatement('DELETE FROM ordering_order_audit_trail WHERE order_id = :id', ['id' => $this->orderId]);
            $connection->executeStatement('DELETE FROM ordering_orders WHERE id = :id', ['id' => $this->orderId]);
        }
        if (null !== $this->slug) {
            $connection->executeStatement('DELETE FROM storefront_product_view WHERE slug = :slug', ['slug' => $this->slug]);
        }
        $connection->executeStatement('DELETE FROM customer_profile WHERE id = :id', ['id' => self::CUSTOMER_ID]);

        parent::tearDown();
    }

    public function testCheckoutCreatesAPendingPaymentForTheLockedTotal(): void
    {
        $client = static::createClient();
        $this->resetRateLimiter();

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $this->seedProduct($connection);
        $this->seedCustomer($connection);
        $client->loginUser(new CustomerUser(self::CUSTOMER_ID, self::CUSTOMER_EMAIL, null, 'ROLE_USER', 'active'));

        $client->jsonRequest('POST', '/api/v1/storefront/orders', [
            'lines' => [['slug' => $this->slug, 'quantity' => 2]],
            'deliveryMethod' => 'home',
            'paymentMethod' => 'Carte bancaire',
        ], ['HTTP_IDEMPOTENCY_KEY' => 'checkout-payment-'.Uuid::v4()->toRfc4122()]);
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

        self::assertIsArray($payment, 'checkout must record the payment it is waiting for');
        self::assertSame('Carte bancaire', $payment['method']);
        self::assertSame($total, (int) $payment['amount_minor'], 'the payment carries the order total locked at placement');
        self::assertSame('TND', $payment['currency']);
        self::assertSame('pending', $payment['status']);
        self::assertSame(1, (int) $payment['version']);

        $ledger = $connection->fetchAllAssociative(
            'SELECT from_status, to_status, version, amount_minor FROM payment_ledger WHERE payment_id = :id ORDER BY created_at',
            ['id' => $payment['id']],
        );
        self::assertCount(1, $ledger, 'opening a payment appends exactly one ledger entry');
        self::assertNull($ledger[0]['from_status']);
        self::assertSame('pending', $ledger[0]['to_status']);
        self::assertSame(1, (int) $ledger[0]['version']);
        self::assertSame($total, (int) $ledger[0]['amount_minor']);
    }

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
        $this->slug = 'test-checkout-payment-'.substr(Uuid::v4()->toRfc4122(), 0, 8);
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
                'name' => 'Checkout Payment Test',
                'email' => self::CUSTOMER_EMAIL,
                'role' => 'ROLE_USER',
                'status' => 'active',
            ],
        );
    }
}
