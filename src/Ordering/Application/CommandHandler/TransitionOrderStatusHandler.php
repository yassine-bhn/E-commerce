<?php

declare(strict_types=1);

namespace App\Ordering\Application\CommandHandler;

use App\Ordering\Application\Command\TransitionOrderStatus;
use App\Ordering\Application\Port\OrderStateReader;
use App\Ordering\Application\Port\OrderTransitionStore;
use App\Ordering\Domain\Model\OptimisticLockException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class TransitionOrderStatusHandler
{
    public function __construct(
        private OrderStateReader $states,
        private OrderTransitionStore $transitions,
    ) {
    }

    public function __invoke(TransitionOrderStatus $command): void
    {
        $current = $this->states->find($command->orderId);

        if (null === $current) {
            throw new \InvalidArgumentException(sprintf('Order with ID "%s" not found.', $command->orderId));
        }

        if ($current->version !== $command->expectedVersion) {
            throw OptimisticLockException::versionMismatch($command->orderId, $command->expectedVersion, $current->version);
        }

        $current->status->ensureCanTransitionTo($command->targetStatus);

        $newVersion = $current->version + 1;

        if (!$this->transitions->transition($command->orderId, $command->expectedVersion, $command->targetStatus, $newVersion)) {
            throw OptimisticLockException::versionMismatch($command->orderId, $command->expectedVersion, $current->version);
        }

        $this->transitions->appendAudit(
            $command->orderId,
            $current->status,
            $command->targetStatus,
            $current->version,
            $newVersion,
            $command->changedBy,
            $command->reason,
        );
    }
}
