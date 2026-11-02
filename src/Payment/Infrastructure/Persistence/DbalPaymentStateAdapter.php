<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Persistence;

use App\Payment\Application\Dto\PaymentSnapshot;
use App\Payment\Application\Port\PaymentStateReader;
use App\Payment\Domain\Model\PaymentStatus;
use Doctrine\DBAL\Connection;

/**
 * DIP-003 — the payment-state read, moved verbatim from the confirm/refund handlers.
 */
final class DbalPaymentStateAdapter implements PaymentStateReader
{
    public function __construct(private Connection $connection)
    {
    }

    public function find(string $paymentId): ?PaymentSnapshot
    {
        $row = $this->connection->fetchAssociative(
            'SELECT order_id, status, version, amount_minor, currency FROM payment WHERE id = :id',
            ['id' => $paymentId],
        );

        if (false === $row) {
            return null;
        }

        return new PaymentSnapshot(
            orderId: (string) $row['order_id'],
            status: PaymentStatus::from((string) $row['status']),
            version: (int) $row['version'],
            amountMinor: (int) $row['amount_minor'],
            currency: (string) $row['currency'],
        );
    }
}
