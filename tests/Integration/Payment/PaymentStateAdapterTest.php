<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment;

use App\Payment\Application\Port\PaymentStateReader;
use App\Payment\Domain\Model\PaymentStatus;
use App\Payment\Infrastructure\Persistence\DbalPaymentStateAdapter;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * solid Task 3.4 — the payment snapshot read against PostgreSQL, with the exact version/status/
 * amount the confirm and refund guards need.
 */
final class PaymentStateAdapterTest extends KernelTestCase
{
    /** @var list<string> */
    private array $createdPaymentIds = [];

    protected function tearDown(): void
    {
        if ([] !== $this->createdPaymentIds) {
            self::connection()->executeStatement(
                'DELETE FROM payment WHERE id IN (:ids)',
                ['ids' => $this->createdPaymentIds],
                ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }

        $this->createdPaymentIds = [];

        parent::tearDown();
    }

    public function testFindAnswersTheExactSnapshotColumns(): void
    {
        $adapter = $this->adapter();
        $paymentId = $this->newPayment('authorized', 4, 13600);

        $snapshot = $adapter->find($paymentId);
        self::assertNotNull($snapshot);
        self::assertSame($this->orderId, $snapshot->orderId);
        self::assertSame(PaymentStatus::AUTHORIZED, $snapshot->status);
        self::assertSame(4, $snapshot->version);
        self::assertSame(13600, $snapshot->amountMinor);
        self::assertSame('TND', $snapshot->currency);
    }

    public function testFindReturnsNullForAMissingPayment(): void
    {
        self::assertNull($this->adapter()->find(Uuid::v7()->toRfc4122()));
    }

    private function adapter(): DbalPaymentStateAdapter
    {
        $adapter = static::getContainer()->get(PaymentStateReader::class);
        \assert($adapter instanceof DbalPaymentStateAdapter);

        return $adapter;
    }

    private string $orderId = '';

    private function newPayment(string $status, int $version, int $amountMinor): string
    {
        $paymentId = Uuid::v7()->toRfc4122();
        $this->createdPaymentIds[] = $paymentId;
        $this->orderId = Uuid::v7()->toRfc4122();
        self::connection()->insert('payment', [
            'id' => $paymentId,
            'order_id' => $this->orderId,
            'status' => $status,
            'version' => $version,
            'amount_minor' => $amountMinor,
            'currency' => 'TND',
            'method' => 'cod',
        ]);

        return $paymentId;
    }

    private static function connection(): Connection
    {
        self::bootKernel();

        $connection = static::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        return $connection;
    }
}
