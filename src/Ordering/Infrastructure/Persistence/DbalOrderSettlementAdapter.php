<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence;

use App\Ordering\Domain\Model\IllegalOrderStateTransitionException;
use App\Ordering\Domain\Model\OrderStatus;
use App\Shared\Application\Port\OrderSettlementPort;
use App\Shared\Domain\Payment\OrderNotPayableException;
use App\Shared\Domain\Payment\OrderNotRefundableException;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final class DbalOrderSettlementAdapter implements OrderSettlementPort
{
    public function __construct(private Connection $connection)
    {
    }

    public function markPaid(string $orderId, string $actorId, string $reason): void
    {
        // RP1.1/RP1.3 — the transition is guarded by the current status in the same statement,
        // so a payment confirmed after the order moved on cannot pay it twice.
        $newVersion = $this->connection->fetchOne(
            'UPDATE ordering_orders
             SET status = :paid, version = version + 1
             WHERE id = :id AND status = :pending
             RETURNING version',
            [
                'paid' => OrderStatus::PAID->value,
                'id' => $orderId,
                'pending' => OrderStatus::PENDING->value,
            ],
        );

        if (false === $newVersion) {
            throw OrderNotPayableException::forOrder($orderId);
        }

        $this->connection->executeStatement(
            'INSERT INTO ordering_order_audit_trail (id, order_id, from_status, to_status, from_version, to_version, changed_by, reason, created_at)
             VALUES (:id, :order_id, :from_status, :to_status, :from_version, :to_version, :changed_by, :reason, :created_at)',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'order_id' => $orderId,
                'from_status' => OrderStatus::PENDING->value,
                'to_status' => OrderStatus::PAID->value,
                'from_version' => (int) $newVersion - 1,
                'to_version' => (int) $newVersion,
                'changed_by' => $actorId,
                'reason' => $reason,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP'),
            ],
        );
    }

    public function markRefunded(string $orderId, string $actorId, string $reason): void
    {
        // Two statuses legitimately lead to 已退款 (已支付 and 已发货), so the row is locked before
        // the enum decides: the trail must name the status the order actually left.
        $current = $this->connection->fetchAssociative(
            'SELECT status, version FROM ordering_orders WHERE id = :id FOR UPDATE',
            ['id' => $orderId],
        );

        if (false === $current) {
            throw OrderNotRefundableException::forOrder($orderId);
        }

        $currentStatus = OrderStatus::from((string) $current['status']);
        try {
            $currentStatus->ensureCanTransitionTo(OrderStatus::REFUNDED);
        } catch (IllegalOrderStateTransitionException) {
            // RP1.3 — the Payment context reports its own refusal; Ordering's vocabulary does not
            // leak across the port.
            throw OrderNotRefundableException::forOrder($orderId);
        }

        $currentVersion = (int) $current['version'];
        $newVersion = $currentVersion + 1;

        $updated = $this->connection->executeStatement(
            'UPDATE ordering_orders SET status = :refunded, version = :new_version WHERE id = :id AND version = :expected_version',
            [
                'refunded' => OrderStatus::REFUNDED->value,
                'new_version' => $newVersion,
                'id' => $orderId,
                'expected_version' => $currentVersion,
            ],
        );

        if (1 !== $updated) {
            throw OrderNotRefundableException::forOrder($orderId);
        }

        $this->connection->executeStatement(
            'INSERT INTO ordering_order_audit_trail (id, order_id, from_status, to_status, from_version, to_version, changed_by, reason, created_at)
             VALUES (:id, :order_id, :from_status, :to_status, :from_version, :to_version, :changed_by, :reason, :created_at)',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'order_id' => $orderId,
                'from_status' => $currentStatus->value,
                'to_status' => OrderStatus::REFUNDED->value,
                'from_version' => $currentVersion,
                'to_version' => $newVersion,
                'changed_by' => $actorId,
                'reason' => $reason,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP'),
            ],
        );
    }
}
