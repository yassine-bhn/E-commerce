<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\LinkGoogleAccount;
use App\Identity\Application\EmailUniqueness;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
        if ($this->alreadyLinked($command->googleId)) {
            return;
        }

        if ($this->link($command->email, $command->googleId)) {
            return;
        }

        try {
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
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent sign-in won the address: the unique index on lower(email) (spec-run-12
            // R7) refuses the second row, and the aborted transaction forbids asking which account
            // owns it. The address does exist — that is what the violation says — so the sign-in is
            // not an error; the next sign-in links the Google identity through the normal path.
            if (EmailUniqueness::isEmailCollision($exception)) {
                return;
            }

            throw $exception;
        }
    }

    private function alreadyLinked(string $googleId): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM customer_profile WHERE google_id = :googleId',
            ['googleId' => $googleId],
        );
    }

    /**
     * Attaches the Google identity to the account that already owns the address; answers whether
     * such an account exists.
     */
    private function link(string $email, string $googleId): bool
    {
        $existing = $this->connection->fetchAssociative(
            'SELECT id, google_id FROM customer_profile WHERE lower(email) = lower(:email) LIMIT 1',
            ['email' => $email],
        );

        if (false === $existing) {
            return false;
        }

        if (null === $existing['google_id']) {
            $this->connection->executeStatement(
                'UPDATE customer_profile SET google_id = :googleId WHERE id = :id',
                ['googleId' => $googleId, 'id' => $existing['id']],
            );
        }

        return true;
    }
}
