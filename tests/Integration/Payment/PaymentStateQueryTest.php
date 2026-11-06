<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment;

use App\Payment\Application\Query\GetPaymentState;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * solid Task 4.8 — the payment state query through the real query.bus: the joined
 * payment/order statuses, and the unknown fallback the admin dashboard relies on.
 */
final class PaymentStateQueryTest extends KernelTestCase
{
    private string $orderId = '';

    private string $paymentId = '';

    protected function tearDown(): void
    {
        if ('' !== $this->paymentId) {
            self::connection()->executeStatement('DELETE FROM payment WHERE id = :id', ['id' => $this->paymentId]);
        }
        if ('' !== $this->orderId) {
            self::connection()->executeStatement('DELETE FROM ordering_orders WHERE id = :id', ['id' => $this->orderId]);
        }

        parent::tearDown();
    }

    public function testAnswersTheJoinedStatusesAndTheUnknownFallback(): void
    {
        $bus = $this->queryBus();
        $this->seedPayment();

        $state = $bus->dispatch(new GetPaymentState($this->paymentId))->last(\Symfony\Component\Messenger\Stamp\HandledStamp::class)?->getResult();
        self::assertSame(
            ['paymentId' => $this->paymentId, 'status' => 'pending', 'version' => 1, 'orderStatus' => '待支付'],
            $state,
        );

        $unknown = $bus->dispatch(new GetPaymentState(Uuid::v7()->toRfc4122()))->last(\Symfony\Component\Messenger\Stamp\HandledStamp::class)?->getResult();
        self::assertSame(
            ['paymentId' => $unknown['paymentId'] ?? '', 'status' => 'unknown', 'version' => 0, 'orderStatus' => 'unknown'],
            $unknown,
            'a missing payment answers the placeholder, not a 404',
        );
    }

    private function seedPayment(): void
    {
        self::bootKernel();
        $this->orderId = Uuid::v7()->toRfc4122();
        self::connection()->insert('ordering_orders', [
            'id' => $this->orderId,
            'customer_id' => Uuid::v7()->toRfc4122(),
            'status' => '待支付',
            'version' => 1,
            'order_number' => 'ZG-PSQ-'.substr($this->orderId, 0, 6),
            'total_minor' => 5000,
            'currency' => 'TND',
        ]);

        $this->paymentId = Uuid::v7()->toRfc4122();
        self::connection()->insert('payment', [
            'id' => $this->paymentId,
            'order_id' => $this->orderId,
            'status' => 'pending',
            'version' => 1,
            'amount_minor' => 5000,
            'currency' => 'TND',
            'method' => 'cod',
        ]);
    }

    private function queryBus(): MessageBusInterface
    {
        self::bootKernel();

        $bus = static::getContainer()->get('messenger.bus.query');
        \assert($bus instanceof MessageBusInterface);

        return $bus;
    }

    private static function connection(): Connection
    {
        self::bootKernel();

        $connection = static::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        return $connection;
    }
}
