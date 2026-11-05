<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Persistence;

use App\Identity\Application\Dto\CustomerProfileData;
use App\Identity\Application\Dto\CustomerRecord;
use App\Identity\Application\Dto\NewCustomer;
use App\Identity\Application\Port\CustomerAccountReader;
use App\Identity\Application\Port\CustomerDeletionReader;
use App\Identity\Application\Port\CustomerDeletionWriter;
use App\Identity\Application\Port\CustomerProfileStore;
use App\Identity\Application\Port\CustomerRegistrar;
use App\Identity\Application\Port\CustomerRoleStore;
use App\Identity\Application\Port\CustomerStatusStore;
use App\Identity\Domain\Model\EmailAlreadyUsedException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * DIP-003 — the one DBAL adapter behind the customer-account ports. The SQL is the handlers'
 * former inline statements, moved verbatim; the e-mail collision mapping lives here because it
 * is a property of the unique index the adapter owns (spec-run-12 R7/R10).
 */
final class DbalCustomerAccountAdapter implements CustomerAccountReader, CustomerDeletionReader, CustomerDeletionWriter, CustomerProfileStore, CustomerRegistrar, CustomerRoleStore, CustomerStatusStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function find(string $id): ?CustomerRecord
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, email, role, status, google_id FROM customer_profile WHERE id = :id',
            ['id' => $id],
        );

        if (false === $row) {
            return null;
        }

        return new CustomerRecord(
            id: (string) $row['id'],
            email: (string) $row['email'],
            role: (string) $row['role'],
            status: (string) $row['status'],
            googleId: null === $row['google_id'] ? null : (string) $row['google_id'],
        );
    }

    public function activeSuperAdminCount(): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM customer_profile WHERE role = 'ROLE_SUPER_ADMIN' AND status != 'banned'",
        );
    }

    public function orderCount(string $customerId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ordering_orders WHERE customer_id = :customer_id',
            ['customer_id' => $customerId],
        );
    }

    public function ban(string $id, string $reason, string $adminId): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP');

        $this->connection->executeStatement(
            'UPDATE customer_profile
             SET status = :status, ban_reason = :ban_reason, banned_by = :banned_by, banned_at = :banned_at
             WHERE id = :id',
            [
                'status' => 'banned',
                'ban_reason' => $reason,
                'banned_by' => $adminId,
                'banned_at' => $now,
                'id' => $id,
            ],
        );
    }

    public function unban(string $id): void
    {
        $this->connection->executeStatement(
            'UPDATE customer_profile
             SET status = :status, ban_reason = NULL, banned_by = NULL, banned_at = NULL
             WHERE id = :id',
            [
                'status' => 'active',
                'id' => $id,
            ],
        );
    }

    public function changeRole(string $id, string $role): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP');

        $this->connection->executeStatement(
            'UPDATE customer_profile SET role = :role, updated_at = :updated_at WHERE id = :id',
            [
                'role' => $role,
                'updated_at' => $now,
                'id' => $id,
            ],
        );
    }

    public function anonymize(string $id, string $anonEmail): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP');

        $this->connection->executeStatement(
            'UPDATE customer_profile
             SET name = :name, email = :email, phone = :phone, company = NULL, mf = NULL, status = :status, updated_at = :updated_at
             WHERE id = :id',
            [
                'name' => 'Utilisateur anonymisé',
                'email' => $anonEmail,
                'phone' => '',
                'status' => 'anonymized',
                'updated_at' => $now,
                'id' => $id,
            ],
        );

        $this->connection->executeStatement(
            'DELETE FROM customer_address WHERE customer_id = :customer_id',
            ['customer_id' => $id],
        );
    }

    public function hardDelete(string $id): void
    {
        $this->connection->executeStatement(
            'DELETE FROM customer_address WHERE customer_id = :customer_id',
            ['customer_id' => $id],
        );
        $this->connection->executeStatement(
            'DELETE FROM customer_profile WHERE id = :id',
            ['id' => $id],
        );
    }

    public function emailExists(string $email): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM customer_profile WHERE lower(email) = lower(:email)',
            ['email' => $email],
        );
    }

    public function register(NewCustomer $customer): void
    {
        try {
            $this->connection->executeStatement(
                'INSERT INTO customer_profile (id, name, email, phone, account_type, company, mf, role, status, password_hash)
                 VALUES (:id, :name, :email, :phone, :type, :company, :mf, :role, :status, :password_hash)',
                [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                    'type' => $customer->accountType,
                    'company' => $customer->company,
                    'mf' => $customer->mf,
                    'role' => 'ROLE_USER',
                    'status' => 'active',
                    'password_hash' => $customer->passwordHash,
                ],
            );
        } catch (UniqueConstraintViolationException $exception) {
            // Two registrations can pass the pre-check at the same time; the unique index on
            // lower(email) (spec-run-12 R7) then rejects the slower one. It is the same conflict,
            // so it leaves as the same domain exception instead of a 500 (R10). The question
            // cannot be asked again here: the command bus runs the handler in a transaction the
            // failure has already aborted, so the answer comes from the constraint PostgreSQL named.
            if (EmailUniqueness::isEmailCollision($exception)) {
                throw EmailAlreadyUsedException::forEmail($customer->email);
            }

            throw $exception;
        }
    }

    public function upsert(CustomerProfileData $profile): void
    {
        try {
            $this->connection->executeStatement(
                'INSERT INTO customer_profile (id, name, email, phone, account_type, company, mf, updated_at)
                 VALUES (:id, :name, :email, :phone, :type, :company, :mf, CURRENT_TIMESTAMP)
                 ON CONFLICT (id) DO UPDATE SET
                    name = EXCLUDED.name,
                    email = EXCLUDED.email,
                    phone = EXCLUDED.phone,
                    account_type = EXCLUDED.account_type,
                    company = EXCLUDED.company,
                    mf = EXCLUDED.mf,
                    updated_at = CURRENT_TIMESTAMP',
                [
                    'id' => $profile->id,
                    'name' => $profile->name,
                    'email' => $profile->email,
                    'phone' => $profile->phone,
                    'type' => $profile->accountType,
                    'company' => $profile->company,
                    'mf' => $profile->mf,
                ],
            );
        } catch (UniqueConstraintViolationException $exception) {
            // The profile update writes the address the customer typed. Since Version20261024000000
            // two accounts cannot share one case-insensitively (spec-run-12 R7), so claiming an
            // address another account owns is the same conflict registration reports (R10) — not
            // the 500 a bare unique violation would become.
            if (EmailUniqueness::isEmailCollision($exception)) {
                throw EmailAlreadyUsedException::forEmail($profile->email);
            }

            throw $exception;
        }
    }
}
