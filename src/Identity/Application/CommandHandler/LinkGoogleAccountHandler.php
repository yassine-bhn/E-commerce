<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\LinkGoogleAccount;
use App\Identity\Application\Port\GoogleAccountLinker;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final class LinkGoogleAccountHandler
{
    public function __construct(
        private GoogleAccountLinker $linker,
    ) {
    }

    public function __invoke(LinkGoogleAccount $command): void
    {
        if ($this->linker->isLinked($command->googleId)) {
            return;
        }

        if ($this->linker->linkByEmail($command->email, $command->googleId)) {
            return;
        }

        $this->linker->register(Uuid::v7()->toRfc4122(), $command->name, $command->email, $command->googleId);
    }
}
