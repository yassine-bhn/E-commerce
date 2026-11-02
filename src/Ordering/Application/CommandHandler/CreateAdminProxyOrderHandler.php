<?php

declare(strict_types=1);

namespace App\Ordering\Application\CommandHandler;

use App\Ordering\Application\Command\CreateAdminProxyOrder;
use App\Ordering\Application\Dto\AdminProxyOrderData;
use App\Ordering\Application\Port\AdminProxyOrderStore;
use App\Shared\Application\Port\PaymentInitiationPort;
use App\Shared\Application\Port\StockReservationPort;
use App\Shared\Application\Service\StorefrontQuoteService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final class CreateAdminProxyOrderHandler
{
    public function __construct(
        private AdminProxyOrderStore $proxyOrders,
        private StorefrontQuoteService $quotes,
        private StockReservationPort $stock,
        private PaymentInitiationPort $payments,
    ) {
    }

    public function __invoke(CreateAdminProxyOrder $command): void
    {
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

        $this->proxyOrders->create(new AdminProxyOrderData(
            orderId: $command->orderId,
            guestCustomerId: Uuid::v7()->toRfc4122(), // Synthetic guest ID for phone/whatsapp orders
            orderNumber: $command->orderNumber,
            orderSource: $command->orderSource->value,
            customerName: $command->customerName,
            customerPhone: $command->customerPhone,
            shippingAddress: $command->shippingAddress,
            createdByAdminId: $command->createdByAdminId,
            adminNotes: $command->adminNotes,
            subtotalMinor: $quote['subtotal']->minorUnits(),
            discountMinor: $quote['discount']->minorUnits(),
            deliveryMinor: $quote['delivery']->minorUnits(),
            totalMinor: $quote['total']->minorUnits(),
            currency: $quote['total']->currency()->value,
            deliveryMethod: $command->deliveryMethod,
            paymentMethod: $command->paymentMethod,
            promoCode: $command->promoCode,
            lines: $quote['pricedLines'],
        ));

        $this->payments->open(
            $command->orderId,
            $command->paymentMethod,
            $quote['total']->minorUnits(),
            $quote['total']->currency()->value,
            $command->createdByAdminId,
        );
    }
}
