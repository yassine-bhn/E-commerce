<?php

declare(strict_types=1);

namespace App\Ordering\Application\CommandHandler;

use App\Ordering\Application\Command\AutoCancelOrder;
use App\Ordering\Application\Command\TransitionOrderStatus;
use App\Ordering\Domain\Model\OrderStatus;
use App\Shared\Application\Port\StockReservationPort;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler(bus: 'command.bus')]
final class AutoCancelOrderHandler
{
    public function __construct(
        private Connection $connection,
        private MessageBusInterface $commandBus,
        private StockReservationPort $stock,
    ) {
    }

    public function __invoke(AutoCancelOrder $command): void
    {
        $order = $this->connection->fetchAssociative(
            'SELECT status, version FROM ordering_orders WHERE id = :id',
            ['id' => $command->orderId],
        );

        if (false === $order || OrderStatus::PENDING->value !== (string) $order['status']) {
            return;
        }

        $this->commandBus->dispatch(new TransitionOrderStatus(
            orderId: $command->orderId,
            expectedVersion: (int) $order['version'],
            targetStatus: OrderStatus::CANCELLED,
            changedBy: 'system:auto-cancel',
            reason: 'Auto-cancel timeout',
        ));

        $this->stock->release($command->orderId);
    }
}
