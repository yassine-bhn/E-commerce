<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\SubmitContactMessage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class SubmitContactMessageHandler
{
    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(SubmitContactMessage $command): void
    {
        $this->connection->executeStatement(
            'INSERT INTO storefront_contact_message (id, name, email, phone, subject, message)
             VALUES (:id, :name, :email, :phone, :subject, :message)',
            [
                'id' => $command->id,
                'name' => $command->name,
                'email' => $command->email,
                'phone' => $command->phone,
                'subject' => $command->subject,
                'message' => $command->message,
            ],
        );
    }
}
