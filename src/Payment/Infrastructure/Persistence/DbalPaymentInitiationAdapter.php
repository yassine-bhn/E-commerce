<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Persistence;

use App\Shared\Application\Port\PaymentInitiationPort;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final class DbalPaymentInitiationAdapter implements PaymentInitiationPort
{
    public function __construct(private Connection $connection)
    {
    }

    public function open(string $orderId, string $method, int $amountMinor, string $currency, string $actorId): void
    {
        $paymentId = Uuid::v7()->toRfc4122();

        $this->connection->executeStatement(
            'INSERT INTO payment (id, order_id, method, amount_minor, currency, status, version)
             VALUES (:id, :order_id, :method, :amount, :currency, :status, 1)',
            [
                'id' => $paymentId,
                'order_id' => $orderId,
                'method' => $method,
                'amount' => $amountMinor,
                'currency' => $currency,
                'status' => 'pending',
            ],
        );

        // ADR-0001 — the ledger is the audit spine: every movement is one appended row,
        // so a payment can always be reconstructed from its own history.
        $this->connection->executeStatement(
            'INSERT INTO payment_ledger (id, payment_id, from_status, to_status, version, amount_minor, actor_id, reason)
             VALUES (:id, :payment_id, NULL, :to_status, 1, :amount, :actor, :reason)',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'payment_id' => $paymentId,
                'to_status' => 'pending',
                'amount' => $amountMinor,
                'actor' => $actorId,
                'reason' => 'Payment opened at order placement',
            ],
        );
    }
}
