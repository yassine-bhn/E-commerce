<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Persistence;

use App\Payment\Domain\Model\PaymentLedger;
use App\Payment\Domain\Model\PaymentTransition;
use App\Payment\Domain\Model\PaymentVersionConflictException;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final class DbalPaymentLedger implements PaymentLedger
{
    public function __construct(private Connection $connection)
    {
    }

    public function record(PaymentTransition $transition): void
    {
        $updated = $this->connection->executeStatement(
            'UPDATE payment
             SET status = :status, version = :version, updated_at = NOW(),
                 provider_reference = COALESCE(:reference, provider_reference)
             WHERE id = :id AND version = :expected_version',
            [
                'status' => $transition->to->value,
                'version' => $transition->expectedVersion + 1,
                'reference' => $transition->providerReference,
                'id' => $transition->paymentId,
                'expected_version' => $transition->expectedVersion,
            ],
        );

        // A zero-row update means the payment moved between the read and this write; throwing
        // rolls the whole command transaction back, including the order settlement (RP1.2).
        if (1 !== $updated) {
            throw PaymentVersionConflictException::versionMismatch($transition->paymentId, $transition->expectedVersion, $this->currentVersion($transition));
        }

        $this->connection->executeStatement(
            'INSERT INTO payment_ledger (id, payment_id, from_status, to_status, version, amount_minor, actor_id, reason)
             VALUES (:id, :payment_id, :from_status, :to_status, :version, :amount, :actor, :reason)',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'payment_id' => $transition->paymentId,
                'from_status' => $transition->from->value,
                'to_status' => $transition->to->value,
                'version' => $transition->expectedVersion + 1,
                'amount' => $transition->amountMinor,
                'actor' => $transition->actorId,
                'reason' => $transition->reason,
            ],
        );
    }

    /**
     * The loser of a race needs to be told what the row actually holds, not what it expected;
     * the payment is never deleted, so a missing row can only mean the caller lost the race.
     */
    private function currentVersion(PaymentTransition $transition): int
    {
        $version = $this->connection->fetchOne(
            'SELECT version FROM payment WHERE id = :id',
            ['id' => $transition->paymentId],
        );

        return false === $version ? $transition->expectedVersion : (int) $version;
    }
}
