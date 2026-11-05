<?php

declare(strict_types=1);

namespace App\Ordering\Application\CommandHandler;

use App\Ordering\Application\Command\TransitionOrderStatus;
use App\Ordering\Domain\Model\OptimisticLockException;
use App\Ordering\Domain\Model\OrderStatus;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final class TransitionOrderStatusHandler
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function __invoke(TransitionOrderStatus $command): void
    {
        $current = $this->connection->fetchAssociative(
            'SELECT status, version FROM ordering_orders WHERE id = :id',
            ['id' => $command->orderId],
        );

        if (false === $current || !isset($current['version'])) {
            throw new \InvalidArgumentException(sprintf('Order with ID "%s" not found.', $command->orderId));
        }

        $currentVersion = (int) $current['version'];
        if ($currentVersion !== $command->expectedVersion) {
            throw OptimisticLockException::versionMismatch($command->orderId, $command->expectedVersion, $currentVersion);
        }

        $currentStatus = OrderStatus::from((string) $current['status']);
        $currentStatus->ensureCanTransitionTo($command->targetStatus);

        $newVersion = $currentVersion + 1;
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP');

        $affected = $this->connection->executeStatement(
            'UPDATE ordering_orders
             SET status = :target_status, version = :new_version
             WHERE id = :id AND version = :expected_version',
            [
                'target_status' => $command->targetStatus->value,
                'new_version' => $newVersion,
                'id' => $command->orderId,
                'expected_version' => $command->expectedVersion,
            ],
        );

        if (0 === $affected) {
            throw OptimisticLockException::versionMismatch($command->orderId, $command->expectedVersion, $currentVersion);
        }

        $this->connection->executeStatement(
            'INSERT INTO ordering_order_audit_trail (id, order_id, from_status, to_status, from_version, to_version, changed_by, reason, created_at)
             VALUES (:id, :order_id, :from_status, :to_status, :from_version, :to_version, :changed_by, :reason, :created_at)',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'order_id' => $command->orderId,
                'from_status' => $currentStatus->value,
                'to_status' => $command->targetStatus->value,
                'from_version' => $currentVersion,
                'to_version' => $newVersion,
                'changed_by' => $command->changedBy,
                'reason' => $command->reason,
                'created_at' => $now,
            ],
        );
    }
}
