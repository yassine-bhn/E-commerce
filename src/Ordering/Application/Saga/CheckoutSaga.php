<?php

declare(strict_types=1);

namespace App\Ordering\Application\Saga;

use App\Ordering\Application\Command\PlaceOrder;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * ECO-003 / ADR-0004 — process-manager entry: dispatches PlaceOrder on command.bus (sync leg).
 */
final class CheckoutSaga
{
    public function __construct(
        private MessageBusInterface $commandBus,
    ) {
    }

    public function startCheckout(PlaceOrder $command): void
    {
        $this->commandBus->dispatch($command);
    }
}
