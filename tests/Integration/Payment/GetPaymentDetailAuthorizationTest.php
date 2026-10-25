<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment;

use App\Payment\Application\Query\GetPaymentDetail;
use App\Payment\Infrastructure\Persistence\DbalPaymentInitiationAdapter;
use App\Payment\Infrastructure\ReadModel\GetPaymentDetailHandler;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * QRY-004 — the ownership filter cannot be reached through HTTP today (the only route is
 * admin-only), so it is proven here directly: a customer sees their own payment and nothing else.
 */
final class GetPaymentDetailAuthorizationTest extends KernelTestCase
{
    private ?string $ownOrderId = null;
    private ?string $strangerOrderId = null;
    private ?string $ownPaymentId = null;
    private ?string $strangerPaymentId = null;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $connection->executeStatement(
            'DELETE FROM payment_ledger WHERE payment_id IN (SELECT id FROM payment WHERE order_id IN (:own, :stranger))',
            ['own' => $this->ownOrderId ?? '', 'stranger' => $this->strangerOrderId ?? ''],
        );
        $connection->executeStatement(
            'DELETE FROM payment WHERE order_id IN (:own, :stranger)',
            ['own' => $this->ownOrderId ?? '', 'stranger' => $this->strangerOrderId ?? ''],
        );
        $connection->executeStatement(
            'DELETE FROM ordering_orders WHERE id IN (:own, :stranger)',
            ['own' => $this->ownOrderId ?? '', 'stranger' => $this->strangerOrderId ?? ''],
        );

        parent::tearDown();
    }

    public function testACustomerSeesOwnPaymentsAndNotThoseOfOthers(): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        try {
            $connection->executeQuery('SELECT 1 FROM payment LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('PostgreSQL schema not migrated (run doctrine:migrations:migrate).');
        }

        $owner = '01929312-7000-7000-8000-0000000000b1';
        $stranger = '01929312-7000-7000-8000-0000000000b2';

        $this->ownOrderId = $this->seedOrder($connection, $owner);
        $this->strangerOrderId = $this->seedOrder($connection, $stranger);

        $initiation = new DbalPaymentInitiationAdapter($connection);
        $initiation->open($this->ownOrderId, 'Carte bancaire', 7000, 'TND', $owner);
        $initiation->open($this->strangerOrderId, 'Carte bancaire', 9000, 'TND', $stranger);

        $this->ownPaymentId = (string) $connection->fetchOne('SELECT id FROM payment WHERE order_id = :order', ['order' => $this->ownOrderId]);
        $this->strangerPaymentId = (string) $connection->fetchOne('SELECT id FROM payment WHERE order_id = :order', ['order' => $this->strangerOrderId]);

        $handler = new GetPaymentDetailHandler($connection);

        $own = $handler(new GetPaymentDetail($this->ownPaymentId, $owner, false));
        self::assertIsArray($own, 'a customer reads the payment of their own order');
        self::assertSame($this->ownPaymentId, $own['paymentId']);
        self::assertSame(7000, $own['amountMinor']);

        $foreign = $handler(new GetPaymentDetail($this->strangerPaymentId, $owner, false));
        self::assertNull($foreign, 'the same customer must not see the payment of another order');

        $asAdmin = $handler(new GetPaymentDetail($this->strangerPaymentId, '01929312-7000-7000-8000-0000000000b3', true));
        self::assertIsArray($asAdmin, 'an admin reads any payment, whatever the requester id is');
        self::assertSame(9000, $asAdmin['amountMinor']);
    }

    private function seedOrder(Connection $connection, string $customerId): string
    {
        $orderId = Uuid::v7()->toRfc4122();

        $connection->executeStatement(
            "INSERT INTO ordering_orders (id, customer_id, status, order_number, total_minor, payment_method)
             VALUES (:id, :customer, :status, :number, 7000, 'Carte bancaire')",
            [
                'id' => $orderId,
                'customer' => $customerId,
                'status' => '待支付',
                'number' => 'ZG-AUTH-'.substr($orderId, 0, 8),
            ],
        );

        return $orderId;
    }
}
