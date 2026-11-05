<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Persistence;

use App\Identity\Application\Port\PasswordResetStore;
use App\Identity\Application\Port\PasswordResetTokenStore;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * DIP-003 — the password-reset adapter: the reset link's customer lookup and the one-shot token
 * lifecycle. consume() marks the token used and answers the customer in one statement, so a
 * token cannot be redeemed twice.
 */
final class DbalPasswordResetStore implements PasswordResetStore, PasswordResetTokenStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function customerByEmail(string $email): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, email FROM customer_profile WHERE lower(email) = lower(:email) LIMIT 1',
            ['email' => $email],
        );

        if (false === $row) {
            return null;
        }

        return ['id' => (string) $row['id'], 'email' => (string) $row['email']];
    }

    public function updatePassword(string $customerId, string $passwordHash): void
    {
        $this->connection->executeStatement(
            'UPDATE customer_profile SET password_hash = :password_hash WHERE id = :id',
            [
                'password_hash' => $passwordHash,
                'id' => $customerId,
            ],
        );
    }

    public function invalidateActive(string $customerId): void
    {
        $this->connection->executeStatement(
            'UPDATE customer_password_reset_token SET used_at = CURRENT_TIMESTAMP
             WHERE customer_id = :customer AND used_at IS NULL',
            ['customer' => $customerId],
        );
    }

    public function issue(string $customerId, string $tokenHash): void
    {
        $this->connection->executeStatement(
            'INSERT INTO customer_password_reset_token (id, customer_id, token_hash, expires_at, created_at)
             VALUES (:id, :customer, :token_hash, CURRENT_TIMESTAMP + INTERVAL \'1 hour\', CURRENT_TIMESTAMP)',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'customer' => $customerId,
                'token_hash' => $tokenHash,
            ],
        );
    }

    public function consume(string $tokenHash): ?string
    {
        $customerId = $this->connection->fetchOne(
            'UPDATE customer_password_reset_token SET used_at = CURRENT_TIMESTAMP
             WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > CURRENT_TIMESTAMP
             RETURNING customer_id',
            ['token_hash' => $tokenHash],
        );

        return false === $customerId || null === $customerId ? null : (string) $customerId;
    }
}
