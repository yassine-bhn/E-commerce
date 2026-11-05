<?php

declare(strict_types=1);

namespace App\Cart\Application\CommandHandler;

interface OrderQuotePort
{
}

interface OrderStockPort
{
}

interface OrderPaymentPort
{
}

interface OrderPlacementPort
{
}

interface OrderOutboxPort
{
}

final class FiveDependenciesHandler
{
    public function __construct(
        private OrderQuotePort $quotes,
        private OrderStockPort $stock,
        private OrderPaymentPort $payments,
        private OrderPlacementPort $placements,
        private OrderOutboxPort $outbox,
    ) {
    }
}
