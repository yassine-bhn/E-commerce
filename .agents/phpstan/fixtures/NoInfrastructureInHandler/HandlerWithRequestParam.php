<?php

declare(strict_types=1);

namespace App\Cart\Application\CommandHandler;

use Symfony\Component\HttpFoundation\Request;

final class HandlerWithRequestParam
{
    public function __construct(private Request $request)
    {
    }
}
