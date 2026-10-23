<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

use App\Ordering\Application\Command\PlaceOrder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * ECO-005 / CMD-006 — a retried checkout carries the same Idempotency-Key, so the replay must
 * stop before the stock reservation and never place a second order.
 */
final class CheckoutStockIdempotencyTest extends KernelTestCase
{
    private const string CUSTOMER_ID = '01929312-7000-7000-8000-000000000093';

    private ?string $slug = null;
    private ?string $firstOrderId = null;
    private ?string $replayOrderId = null;
    private ?string $idempotencyKey = null;

    protected function tearDown(): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        foreach ([$this->firstOrderId, $this->replayOrderId] as $orderId) {
            if (null === $orderId) {
                continue;
            }
            $connection->executeStatement('DELETE FROM inventory_reservation WHERE order_id = :id', ['id' => $orderId]);
            $connection->executeStatement('DELETE FROM ordering_order_lines WHERE order_id = :id', ['id' => $orderId]);
            $connection->executeStatement('DELETE FROM ordering_order_audit_trail WHERE order_id = :id', ['id' => $orderId]);
            $connection->executeStatement('DELETE FROM ordering_orders WHERE id = :id', ['id' => $orderId]);
            $connection->executeStatement('DELETE FROM outbox WHERE aggregate_id = :id', ['id' => $orderId]);
        }
        if (null !== $this->idempotencyKey) {
            $connection->executeStatement('DELETE FROM processed_commands WHERE key = :key', ['key' => $this->idempotencyKey]);
        }
        if (null !== $this->slug) {
            $connection->executeStatement('DELETE FROM storefront_product_view WHERE slug = :slug', ['slug' => $this->slug]);
        }
        $connection->executeStatement('DELETE FROM customer_profile WHERE id = :id', ['id' => self::CUSTOMER_ID]);

        parent::tearDown();
    }

    public function testReplayedIdempotencyKeyReservesStockOnce(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);

        $this->slug = 'test-idem-checkout-'.substr(Uuid::v4()->toRfc4122(), 0, 8);
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
        $connection->insert('customer_profile', [
            'id' => self::CUSTOMER_ID,
            'name' => 'Checkout Idempotency Test',
            'email' => 'checkout.idempotency@example.com',
            'role' => 'ROLE_USER',
            'status' => 'active',
        ]);

        /** @var MessageBusInterface $commandBus */
        $commandBus = $container->get('messenger.bus.command');

        $this->idempotencyKey = 'checkout-idem-'.Uuid::v4()->toRfc4122();
        $this->firstOrderId = Uuid::v7()->toRfc4122();
        $this->replayOrderId = Uuid::v7()->toRfc4122();

        $commandBus->dispatch($this->placeOrder($this->firstOrderId, 'ZG-IDEM-101'));
        $commandBus->dispatch($this->placeOrder($this->replayOrderId, 'ZG-IDEM-102'));

        self::assertSame(3, (int) $connection->fetchOne(
            'SELECT stock_quantity FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $this->slug],
        ));
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM inventory_reservation WHERE product_slug = :slug',
            ['slug' => $this->slug],
        ));
        self::assertSame('reserved', $connection->fetchOne(
            'SELECT status FROM inventory_reservation WHERE order_id = :id AND product_slug = :slug',
            ['id' => $this->firstOrderId, 'slug' => $this->slug],
        ));
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM ordering_orders WHERE id = :id',
            ['id' => $this->replayOrderId],
        ));
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM ordering_orders WHERE customer_id = :id',
            ['id' => self::CUSTOMER_ID],
        ));
    }

    private function placeOrder(string $orderId, string $orderNumber): PlaceOrder
    {
        return new PlaceOrder(
            orderId: $orderId,
            customerId: self::CUSTOMER_ID,
            idempotencyKey: (string) $this->idempotencyKey,
            orderNumber: $orderNumber,
            lines: [['slug' => (string) $this->slug, 'quantity' => 2]],
            deliveryMethod: 'home',
            paymentMethod: 'Carte bancaire',
            promoCode: null,
        );
    }
}
