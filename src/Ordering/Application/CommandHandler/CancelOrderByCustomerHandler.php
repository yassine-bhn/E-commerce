<?php

declare(strict_types=1);

namespace App\Ordering\Application\CommandHandler;

use App\Ordering\Application\Command\CancelOrderByCustomer;
use App\Ordering\Application\Command\TransitionOrderStatus;
use App\Ordering\Domain\Model\OrderNotFoundException;
use App\Ordering\Domain\Model\OrderStatus;
use App\Shared\Application\Port\StockReservationPort;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[AsMessageHandler(bus: 'command.bus')]
final class CancelOrderByCustomerHandler
{
    public function __construct(
        private Connection $connection,
        private MessageBusInterface $commandBus,
        private StockReservationPort $stock,
    ) {
    }

    public function __invoke(CancelOrderByCustomer $command): void
    {
        $order = $this->connection->fetchAssociative(
            'SELECT customer_id, status FROM ordering_orders WHERE id = :id',
            ['id' => $command->orderId],
        );

        if (false === $order) {
            throw new OrderNotFoundException($command->orderId);
        }
        if ((string) $order['customer_id'] !== $command->customerId) {
            throw new AccessDeniedException('This order belongs to another customer.');
        }

        $status = OrderStatus::from((string) $order['status']);
        $status->ensureCanTransitionTo(OrderStatus::CANCELLED);

        $this->commandBus->dispatch(new TransitionOrderStatus(
            orderId: $command->orderId,
            expectedVersion: $command->expectedVersion,
            targetStatus: OrderStatus::CANCELLED,
            changedBy: $command->customerId,
            reason: 'Customer cancellation',
        ));

        $this->stock->release($command->orderId);
    }
}
