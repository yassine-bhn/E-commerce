<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence;

use App\Ordering\Application\Dto\OrderState;
use App\Ordering\Application\Port\OrderStateReader;
use App\Ordering\Application\Port\OrderTransitionStore;
use App\Ordering\Domain\Model\OrderStatus;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * DIP-003 — the order-state adapter: the status/version read the guards need, the optimistic
 * status write and its audit row, moved verbatim from the transition handler. The customer read
 * has its own adapter: both ports name their read `find`, so one class cannot implement the two
 * contracts.
 */
final class DbalOrderStateAdapter implements OrderStateReader, OrderTransitionStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function find(string $orderId): ?OrderState
    {
        $row = $this->connection->fetchAssociative(
            'SELECT status, version FROM ordering_orders WHERE id = :id',
            ['id' => $orderId],
        );

        if (false === $row || !isset($row['version'])) {
            return null;
        }

        return new OrderState(
            status: OrderStatus::from((string) $row['status']),
            version: (int) $row['version'],
        );
    }

    public function transition(string $orderId, int $expectedVersion, OrderStatus $target, int $newVersion): bool
    {
        $affected = $this->connection->executeStatement(
            'UPDATE ordering_orders
             SET status = :target_status, version = :new_version
             WHERE id = :id AND version = :expected_version',
            [
                'target_status' => $target->value,
                'new_version' => $newVersion,
                'id' => $orderId,
                'expected_version' => $expectedVersion,
            ],
        );

        return 0 !== $affected;
    }

    public function appendAudit(
        string $orderId,
        OrderStatus $from,
        OrderStatus $to,
        int $fromVersion,
        int $toVersion,
        string $changedBy,
        string $reason,
    ): void {
        $this->connection->executeStatement(
            'INSERT INTO ordering_order_audit_trail (id, order_id, from_status, to_status, from_version, to_version, changed_by, reason, created_at)
             VALUES (:id, :order_id, :from_status, :to_status, :from_version, :to_version, :changed_by, :reason, :created_at)',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'order_id' => $orderId,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'changed_by' => $changedBy,
                'reason' => $reason,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP'),
            ],
        );
    }
}
