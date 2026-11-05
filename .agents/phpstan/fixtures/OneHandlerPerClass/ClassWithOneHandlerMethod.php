<?php

declare(strict_types=1);

namespace App\Cart\Application\CommandHandler;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class ClassWithOneHandlerMethod
{
    #[AsMessageHandler(bus: 'command.bus')]
    public function __invoke(): void
    {
    }
}
