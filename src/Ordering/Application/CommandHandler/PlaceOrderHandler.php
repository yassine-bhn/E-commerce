<?php

declare(strict_types=1);

namespace App\Ordering\Application\CommandHandler;

use App\Ordering\Application\Command\PlaceOrder;
use App\Ordering\Domain\Model\Order;
use App\Ordering\Domain\Model\OrderId;
use App\Ordering\Domain\Model\OrderLine;
use App\Ordering\Domain\Repository\OrderRepository;
use App\Shared\Application\Service\StorefrontQuoteService;
use App\Shared\Domain\Money;
use App\Shared\Domain\Outbox\OutboxPort;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final class PlaceOrderHandler
{
    public function __construct(
        private Connection $connection,
        private StorefrontQuoteService $quotes,
        private OrderRepository $orders,
        private OutboxPort $outbox,
    ) {
    }

    public function __invoke(PlaceOrder $command): void
    {
        $inserted = $this->connection->executeStatement(
            'INSERT INTO processed_commands (key) VALUES (:key) ON CONFLICT DO NOTHING',
            ['key' => $command->idempotencyKey],
        );
        if (0 === $inserted) {
            return;
        }

        $quote = $this->quotes->quote($command->lines, $command->promoCode, $command->deliveryMethod);
        $currency = $quote['total']->currency();

        $lines = array_map(
            static fn (array $line): OrderLine => OrderLine::snapshot(
                Uuid::v7()->toRfc4122(),
                $line['slug'],
                $line['name'],
                $line['variant'],
                Money::ofMinor($line['unitPriceMinor'], $currency),
                $line['quantity'],
            ),
            $quote['pricedLines'],
        );

        $order = Order::placeOnline(
            new OrderId($command->orderId),
            $command->customerId,
            $command->orderNumber,
            $quote['subtotal'],
            $quote['discount'],
            $quote['delivery'],
            $quote['total'],
            $command->deliveryMethod,
            $command->paymentMethod,
            $command->promoCode,
            $lines,
            new \DateTimeImmutable(),
        );

        $this->orders->save($order);
        $this->outbox->append(Order::class, $command->orderId, $order->releaseEvents());
    }
}
