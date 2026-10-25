<?php

declare(strict_types=1);

namespace App\Ordering\Application\CommandHandler;

use App\Ordering\Application\Command\PlaceOrder;
use App\Ordering\Domain\Model\Order;
use App\Ordering\Domain\Model\OrderId;
use App\Ordering\Domain\Model\OrderLine;
use App\Ordering\Domain\Repository\OrderRepository;
use App\Shared\Application\Port\PaymentInitiationPort;
use App\Shared\Application\Port\StockReservationPort;
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
        private StockReservationPort $stock,
        private PaymentInitiationPort $payments,
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

        $this->stock->reserve(
            $command->orderId,
            array_map(
                static fn (array $line): array => [
                    'slug' => (string) $line['slug'],
                    'quantity' => (int) $line['quantity'],
                ],
                $quote['pricedLines'],
            ),
        );

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

        // ADR-0004 — only the *record* is synchronous; authorizing the card stays off the
        // request path, so the customer never waits on a gateway to get an order number.
        $this->payments->open(
            $command->orderId,
            $command->paymentMethod,
            $quote['total']->minorUnits(),
            $currency->value,
            $command->customerId,
        );

        $this->outbox->append(Order::class, $command->orderId, $order->releaseEvents());
    }
}
