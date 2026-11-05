<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\SubscribeNewsletter;
use App\Identity\Application\Port\NewsletterStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class SubscribeNewsletterHandler
{
    public function __construct(private NewsletterStore $subscribers)
    {
    }

    public function __invoke(SubscribeNewsletter $command): void
    {
        $this->subscribers->subscribe($command->id, $command->email);
    }
}
