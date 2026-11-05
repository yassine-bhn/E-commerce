<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\SubmitContactMessage;
use App\Identity\Application\Port\ContactMessageStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class SubmitContactMessageHandler
{
    public function __construct(private ContactMessageStore $messages)
    {
    }

    public function __invoke(SubmitContactMessage $command): void
    {
        $this->messages->store(
            $command->id,
            $command->name,
            $command->email,
            $command->phone,
            $command->subject,
            $command->message,
        );
    }
}
