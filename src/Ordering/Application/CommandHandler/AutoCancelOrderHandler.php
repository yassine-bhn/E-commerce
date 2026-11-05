<?php

declare(strict_types=1);

namespace App\Ordering\Application\CommandHandler;

use App\Ordering\Application\Command\AutoCancelOrder;
use App\Ordering\Application\Command\TransitionOrderStatus;
use App\Ordering\Application\Port\OrderStateReader;
use App\Ordering\Domain\Model\OrderStatus;
use App\Shared\Application\Port\StockReservationPort;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler(bus: 'command.bus')]
final class AutoCancelOrderHandler
{
    public function __construct(
        private OrderStateReader $states,
        private MessageBusInterface $commandBus,
        private StockReservationPort $stock,
    ) {
    }

    public function __invoke(AutoCancelOrder $command): void
    {
        $order = $this->states->find($command->orderId);

        if (null === $order || OrderStatus::PENDING !== $order->status) {
            return;
        }

        $this->commandBus->dispatch(new TransitionOrderStatus(
            orderId: $command->orderId,
            expectedVersion: $order->version,
            targetStatus: OrderStatus::CANCELLED,
            changedBy: 'system:auto-cancel',
            reason: 'Auto-cancel timeout',
        ));

        $this->stock->release($command->orderId);
    }
}
