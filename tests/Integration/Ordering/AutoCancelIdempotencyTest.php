<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

use App\Ordering\Application\Command\PlaceOrder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class AutoCancelIdempotencyTest extends KernelTestCase
{
    private const string CUSTOMER_ID = '01929312-7000-7000-8000-000000000094';

    private ?string $pendingOrderId = null;
    private ?string $paidOrderId = null;
    private ?string $pendingSlug = null;
    private ?string $paidSlug = null;

    protected function tearDown(): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        foreach ([$this->pendingOrderId, $this->paidOrderId] as $orderId) {
            if (null === $orderId) {
                continue;
            }
            $connection->executeStatement('DELETE FROM inventory_reservation WHERE order_id = :id', ['id' => $orderId]);
            $connection->executeStatement('DELETE FROM ordering_order_lines WHERE order_id = :id', ['id' => $orderId]);
            $connection->executeStatement('DELETE FROM ordering_order_audit_trail WHERE order_id = :id', ['id' => $orderId]);
            $connection->executeStatement('DELETE FROM ordering_orders WHERE id = :id', ['id' => $orderId]);
        }
        foreach ([$this->pendingSlug, $this->paidSlug] as $slug) {
            if (null !== $slug) {
                $connection->executeStatement('DELETE FROM storefront_product_view WHERE slug = :slug', ['slug' => $slug]);
            }
        }
        $connection->executeStatement('DELETE FROM customer_profile WHERE id = :id', ['id' => self::CUSTOMER_ID]);
        $connection->executeStatement('DELETE FROM processed_commands WHERE key LIKE :prefix', ['prefix' => 'auto-cancel-idem-%']);

        parent::tearDown();
    }

    public function testAutoCancelIsIdempotentAndLeavesPaidOrdersUntouched(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);

        $this->pendingSlug = $this->insertTrackedProduct($connection, 'test-idem-pending-');
        $this->paidSlug = $this->insertTrackedProduct($connection, 'test-idem-paid-');
        $connection->insert('customer_profile', [
            'id' => self::CUSTOMER_ID,
            'name' => 'Idempotency Test',
            'email' => 'auto.cancel.idem@example.com',
            'role' => 'ROLE_USER',
            'status' => 'active',
        ]);

        /** @var MessageBusInterface $commandBus */
        $commandBus = $container->get('messenger.bus.command');

        $this->pendingOrderId = Uuid::v7()->toRfc4122();
        $commandBus->dispatch(new PlaceOrder(
            orderId: $this->pendingOrderId,
            customerId: self::CUSTOMER_ID,
            idempotencyKey: 'auto-cancel-idem-pending-'.$this->pendingOrderId,
            orderNumber: 'ZG-IDEM-001',
            lines: [['slug' => $this->pendingSlug, 'quantity' => 2]],
            deliveryMethod: 'home',
            paymentMethod: 'Carte bancaire',
            promoCode: null,
        ));

        $this->paidOrderId = Uuid::v7()->toRfc4122();
        $commandBus->dispatch(new PlaceOrder(
            orderId: $this->paidOrderId,
            customerId: self::CUSTOMER_ID,
            idempotencyKey: 'auto-cancel-idem-paid-'.$this->paidOrderId,
            orderNumber: 'ZG-IDEM-002',
            lines: [['slug' => $this->paidSlug, 'quantity' => 1]],
            deliveryMethod: 'home',
            paymentMethod: 'Carte bancaire',
            promoCode: null,
        ));

        $connection->executeStatement('UPDATE ordering_orders SET status = :paid WHERE id = :id', ['paid' => '已支付', 'id' => $this->paidOrderId]);
        $connection->executeStatement("UPDATE ordering_orders SET placed_at = NOW() - INTERVAL '2 hours' WHERE id IN (:a, :b)", ['a' => $this->pendingOrderId, 'b' => $this->paidOrderId]);

        $kernel = self::$kernel;
        self::assertInstanceOf(KernelInterface::class, $kernel);
        $application = new Application($kernel);
        $command = $application->find('app:orders:auto-cancel');

        $firstRun = new CommandTester($command);
        $firstRun->execute(['--timeout-minutes' => '30']);

        $secondRun = new CommandTester($command);
        $secondRun->execute(['--timeout-minutes' => '30']);

        self::assertSame('已取消', $connection->fetchOne('SELECT status FROM ordering_orders WHERE id = :id', ['id' => $this->pendingOrderId]));
        self::assertSame(2, (int) $connection->fetchOne('SELECT version FROM ordering_orders WHERE id = :id', ['id' => $this->pendingOrderId]));
        self::assertSame(5, (int) $connection->fetchOne('SELECT stock_quantity FROM storefront_product_view WHERE slug = :slug', ['slug' => $this->pendingSlug]));
        self::assertSame('released', $connection->fetchOne('SELECT status FROM inventory_reservation WHERE order_id = :id', ['id' => $this->pendingOrderId]));
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM ordering_order_audit_trail WHERE order_id = :id AND reason = :reason',
            ['id' => $this->pendingOrderId, 'reason' => 'Auto-cancel timeout'],
        ));

        self::assertSame('已支付', $connection->fetchOne('SELECT status FROM ordering_orders WHERE id = :id', ['id' => $this->paidOrderId]));
        self::assertSame(1, (int) $connection->fetchOne('SELECT version FROM ordering_orders WHERE id = :id', ['id' => $this->paidOrderId]));
        self::assertSame(4, (int) $connection->fetchOne('SELECT stock_quantity FROM storefront_product_view WHERE slug = :slug', ['slug' => $this->paidSlug]));
        self::assertSame('reserved', $connection->fetchOne('SELECT status FROM inventory_reservation WHERE order_id = :id', ['id' => $this->paidOrderId]));

        self::assertStringContainsString('0 cancelled', $secondRun->getDisplay());
    }

    private function insertTrackedProduct(Connection $connection, string $prefix): string
    {
        $slug = $prefix.substr(Uuid::v4()->toRfc4122(), 0, 8);
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
                'slug' => $slug,
                'source' => 'baignoire-d-code',
            ],
        );

        return $slug;
    }
}
