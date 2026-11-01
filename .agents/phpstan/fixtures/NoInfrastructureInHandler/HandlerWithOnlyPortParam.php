<?php

declare(strict_types=1);

namespace App\Cart\Application\CommandHandler;

interface CheckoutStockReserver
{
}

final class HandlerWithOnlyPortParam
{
    public function __construct(private CheckoutStockReserver $stock)
    {
    }
}
