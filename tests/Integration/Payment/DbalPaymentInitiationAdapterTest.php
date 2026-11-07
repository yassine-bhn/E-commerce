<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment;

use App\Tests\Integration\Contract\AbstractPaymentInitiationPortContractTest;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * LSP-003 — the contract bound to the DBAL adapter against PostgreSQL. LSP-003 is "not
 * applicable until a second gateway exists" — this single adapter is the bound reference.
 */
final class DbalPaymentInitiationAdapterTest extends AbstractPaymentInitiationPortContractTest
{
    protected function cleanup(): void
    {
        $connection = $this->connection();

        if ('' !== $this->orderId) {
            $connection->executeStatement(
                'DELETE FROM payment_ledger WHERE payment_id IN (SELECT id FROM payment WHERE order_id = :order)',
                ['order' => $this->orderId],
            );
            $connection->executeStatement('DELETE FROM payment WHERE order_id = :order', ['order' => $this->orderId]);
            $connection->executeStatement('DELETE FROM ordering_order_audit_trail WHERE order_id = :id', ['id' => $this->orderId]);
            $connection->executeStatement('DELETE FROM ordering_order_lines WHERE order_id = :id', ['id' => $this->orderId]);
            $connection->executeStatement('DELETE FROM ordering_orders WHERE id = :id', ['id' => $this->orderId]);
        }

        $this->orderId = '';
    }

    protected function tearDown(): void
    {
        $this->cleanup();

        parent::tearDown();
    }

    protected function seedOrder(): void
    {
        $this->connection()->insert('ordering_orders', [
            'id' => $this->orderId,
            'customer_id' => Uuid::v7()->toRfc4122(),
            'status' => '待支付',
            'version' => 1,
            'order_number' => 'ZG-PAY-'.substr($this->orderId, 0, 6),
            'total_minor' => 13600,
            'currency' => 'TND',
        ]);
    }

    protected function connection(): Connection
    {
        self::bootKernel();

        $connection = static::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        return $connection;
    }
}
