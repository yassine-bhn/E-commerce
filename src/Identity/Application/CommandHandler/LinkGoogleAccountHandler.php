<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\LinkGoogleAccount;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final class LinkGoogleAccountHandler
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function __invoke(LinkGoogleAccount $command): void
    {
        $alreadyLinked = $this->connection->fetchOne(
            'SELECT 1 FROM customer_profile WHERE google_id = :googleId',
            ['googleId' => $command->googleId],
        );

        if (false !== $alreadyLinked) {
            return;
        }

        $existing = $this->connection->fetchAssociative(
            'SELECT id, google_id FROM customer_profile WHERE lower(email) = lower(:email) LIMIT 1',
            ['email' => $command->email],
        );

        if (false !== $existing) {
            if (null === $existing['google_id']) {
                $this->connection->executeStatement(
                    'UPDATE customer_profile SET google_id = :googleId WHERE id = :id',
                    ['googleId' => $command->googleId, 'id' => $existing['id']],
                );
            }

            return;
        }

        $this->connection->executeStatement(
            'INSERT INTO customer_profile (id, name, email, phone, account_type, role, status, password_hash, google_id)
             VALUES (:id, :name, :email, \'\', \'particulier\', \'ROLE_USER\', \'active\', NULL, :google_id)',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'name' => $command->name,
                'email' => $command->email,
                'google_id' => $command->googleId,
            ],
        );
    }
}
