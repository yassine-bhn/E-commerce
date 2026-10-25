<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

use App\Ordering\Application\Command\PlaceOrder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class PlaceOrderOutboxTest extends KernelTestCase
{
    public function testPlaceOrderPersistsAggregateAndOutboxEvent(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);

        try {
            $connection->executeQuery('SELECT 1 FROM outbox LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('PostgreSQL schema not migrated (run doctrine:migrations:migrate).');
        }

        $orderId = '55555555-5555-5555-5555-555555555555';
        $connection->executeStatement('DELETE FROM processed_commands WHERE key = :key', ['key' => 'integration-place-order-'.$orderId]);
        $connection->executeStatement(
            'DELETE FROM payment_ledger WHERE payment_id IN (SELECT id FROM payment WHERE order_id = :id)',
            ['id' => $orderId],
        );
        $connection->executeStatement('DELETE FROM payment WHERE order_id = :id', ['id' => $orderId]);
        $connection->executeStatement('DELETE FROM ordering_order_lines WHERE order_id = :id', ['id' => $orderId]);
        $connection->executeStatement('DELETE FROM ordering_orders WHERE id = :id', ['id' => $orderId]);
        $connection->executeStatement('DELETE FROM outbox WHERE aggregate_id = :id', ['id' => $orderId]);

        /** @var MessageBusInterface $commandBus */
        $commandBus = $container->get('messenger.bus.command');
        $commandBus->dispatch(new PlaceOrder(
            orderId: $orderId,
            customerId: '01929312-7000-7000-8000-000000000001',
            idempotencyKey: 'integration-place-order-'.$orderId,
            orderNumber: 'ZG-INTEG-001',
            lines: [['slug' => 'baignoire-d-code', 'quantity' => 1]],
            deliveryMethod: 'home',
            paymentMethod: 'Carte bancaire',
            promoCode: null,
        ));

        $status = $connection->fetchOne('SELECT status FROM ordering_orders WHERE id = :id', ['id' => $orderId]);
        self::assertSame('待支付', $status);

        $version = (int) $connection->fetchOne('SELECT version FROM ordering_orders WHERE id = :id', ['id' => $orderId]);
        self::assertSame(1, $version);

        $eventType = $connection->fetchOne(
            'SELECT event_type FROM outbox WHERE aggregate_id = :id ORDER BY occurred_on DESC LIMIT 1',
            ['id' => $orderId],
        );
        self::assertSame('App\Ordering\Domain\Event\OrderPlaced', $eventType);
    }
}
