<?php

declare(strict_types=1);

namespace App\Cart\Application\CommandHandler;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class ClassWithTwoHandlerMethods
{
    #[AsMessageHandler(bus: 'command.bus')]
    public function first(): void
    {
    }

    #[AsMessageHandler(bus: 'command.bus')]
    public function second(): void
    {
    }
}
