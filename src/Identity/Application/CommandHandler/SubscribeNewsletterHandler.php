<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\SubscribeNewsletter;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class SubscribeNewsletterHandler
{
    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(SubscribeNewsletter $command): void
    {
        $this->connection->executeStatement(
            'INSERT INTO storefront_newsletter_subscriber (id, email)
             VALUES (:id, :email)
             ON CONFLICT (email) DO NOTHING',
            [
                'id' => $command->id,
                'email' => strtolower(trim($command->email)),
            ],
        );
    }
}
