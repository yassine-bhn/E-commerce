<?php

declare(strict_types=1);

namespace App\Tests\Integration\Contract;

use App\Shared\Application\Port\PaymentInitiationPort;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LSP-003 (solid Task 5.2) — the contract every PaymentInitiationPort adapter must honour:
 * open() creates one pending payment and one ledger row with the exact amount/currency captured
 * at placement, and never re-derives the amount from the catalog. Not applicable until a second
 * gateway exists — the single adapter is bound below.
 */
abstract class AbstractPaymentInitiationPortContractTest extends KernelTestCase
{
    protected string $orderId = '';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function port(): PaymentInitiationPort
    {
        $port = static::getContainer()->get(PaymentInitiationPort::class);
        \assert($port instanceof PaymentInitiationPort);

        return $port;
    }

    abstract protected function cleanup(): void;

    public function testOpenCreatesOnePendingPaymentAndOneLedgerRowWithTheExactAmount(): void
    {
        $this->orderId = \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
        $this->seedOrder();

        $this->port()->open($this->orderId, 'cod', 13600, 'TND', 'system');

        $connection = $this->connection();

        $payments = $connection->fetchAllAssociative(
            'SELECT id, status, version, amount_minor, currency, method FROM payment WHERE order_id = :order',
            ['order' => $this->orderId],
        );
        self::assertCount(1, $payments, 'open() creates exactly one payment');
        self::assertSame('pending', $payments[0]['status']);
        self::assertSame(1, (int) $payments[0]['version']);
        self::assertSame(13600, (int) $payments[0]['amount_minor'], 'the amount is captured, not re-derived');
        self::assertSame('TND', $payments[0]['currency']);
        self::assertSame('cod', $payments[0]['method']);

        $ledger = $connection->fetchAllAssociative(
            'SELECT from_status, to_status, version, amount_minor FROM payment_ledger WHERE payment_id = :id',
            ['id' => $payments[0]['id'] ?? ''],
        );
        self::assertCount(1, $ledger, 'exactly one opening ledger entry');
        self::assertNull($ledger[0]['from_status']);
        self::assertSame('pending', $ledger[0]['to_status']);
        self::assertSame(13600, (int) $ledger[0]['amount_minor']);
    }

    public function testASecondOpenIsRejectedConsistently(): void
    {
        $this->orderId = \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
        $this->seedOrder();

        $this->port()->open($this->orderId, 'cod', 13600, 'TND', 'system');

        // The documented behavior (ADR-0001): the payment.order_id side of the one-order-one-payment
        // rule is enforced by the schema, so a second open is refused rather than silently duplicated.
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);

        $this->port()->open($this->orderId, 'cod', 13600, 'TND', 'system');
    }

    abstract protected function seedOrder(): void;

    abstract protected function connection(): Connection;
}
