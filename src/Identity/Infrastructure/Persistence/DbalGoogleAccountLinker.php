<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Persistence;

use App\Identity\Application\Port\GoogleAccountLinker;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * DIP-003 — the Google sign-in adapter. A lost address race is not an error: the unique index
 * on lower(email) (spec-run-12 R7) refuses the second row, the aborted transaction forbids
 * asking which account owns it, and the next sign-in links the identity through the normal path.
 */
final class DbalGoogleAccountLinker implements GoogleAccountLinker
{
    public function __construct(private Connection $connection)
    {
    }

    public function isLinked(string $googleId): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM customer_profile WHERE google_id = :googleId',
            ['googleId' => $googleId],
        );
    }

    public function linkByEmail(string $email, string $googleId): bool
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

    public function register(string $id, string $name, string $email, string $googleId): void
    {
        try {
            $this->connection->executeStatement(
                'INSERT INTO customer_profile (id, name, email, phone, account_type, role, status, password_hash, google_id)
                 VALUES (:id, :name, :email, \'\', \'particulier\', \'ROLE_USER\', \'active\', NULL, :google_id)',
                [
                    'id' => $id,
                    'name' => $name,
                    'email' => $email,
                    'google_id' => $googleId,
                ],
            );
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent sign-in won the address: the address does exist — that is what the
            // violation says — so the sign-in is not an error; the next sign-in links the Google
            // identity through the normal path.
            if (EmailUniqueness::isEmailCollision($exception)) {
                return;
            }

            throw $exception;
        }
    }
}
