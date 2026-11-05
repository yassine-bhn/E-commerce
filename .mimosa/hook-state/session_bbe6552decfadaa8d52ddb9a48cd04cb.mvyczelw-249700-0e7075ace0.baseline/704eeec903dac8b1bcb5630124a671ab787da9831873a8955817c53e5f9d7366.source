<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\ResetPassword;
use App\Identity\Domain\Model\InvalidPasswordResetTokenException;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class ResetPasswordHandler
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function __invoke(ResetPassword $command): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, customer_id FROM customer_password_reset_token
             WHERE token_hash = :token_hash
               AND used_at IS NULL
               AND expires_at > CURRENT_TIMESTAMP
             LIMIT 1',
            ['token_hash' => hash('sha256', $command->token)],
        );

        if (false === $row) {
            throw InvalidPasswordResetTokenException::invalid();
        }

        $this->connection->executeStatement(
            'UPDATE customer_profile SET password_hash = :password_hash WHERE id = :id',
            [
                'password_hash' => password_hash($command->newPassword, PASSWORD_BCRYPT),
                'id' => $row['customer_id'],
            ],
        );

        $this->connection->executeStatement(
            'UPDATE customer_password_reset_token SET used_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => $row['id']],
        );
    }
}
