<?php

declare(strict_types=1);

namespace App\Cart\Application\CommandHandler;

interface CartPricerPort
{
}

final class HandlerWithPortParam
{
    public function __construct(private CartPricerPort $pricer)
    {
    }
}
