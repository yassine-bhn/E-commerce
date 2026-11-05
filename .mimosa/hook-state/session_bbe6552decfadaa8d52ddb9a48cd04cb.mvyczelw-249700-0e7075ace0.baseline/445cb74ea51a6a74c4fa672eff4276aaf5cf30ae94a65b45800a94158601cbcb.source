<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\RegisterCustomer;
use App\Identity\Application\EmailUniqueness;
use App\Identity\Domain\Model\EmailAlreadyUsedException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class RegisterCustomerHandler
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function __invoke(RegisterCustomer $command): void
    {
        if ($this->emailTaken($command->email)) {
            throw EmailAlreadyUsedException::forEmail($command->email);
        }

        try {
            $this->connection->executeStatement(
                'INSERT INTO customer_profile (id, name, email, phone, account_type, company, mf, role, status, password_hash)
                 VALUES (:id, :name, :email, :phone, :type, :company, :mf, :role, :status, :password_hash)',
                [
                    'id' => $command->id,
                    'name' => $command->name,
                    'email' => $command->email,
                    'phone' => $command->phone,
                    'type' => $command->accountType,
                    'company' => $command->company,
                    'mf' => $command->mf,
                    'role' => 'ROLE_USER',
                    'status' => 'active',
                    'password_hash' => password_hash($command->password, PASSWORD_BCRYPT),
                ],
            );
        } catch (UniqueConstraintViolationException $exception) {
            // Two registrations can pass the check above at the same time; the unique index on
            // lower(email) (spec-run-12 R7) then rejects the slower one. It is the same conflict, so
            // it leaves as the same domain exception instead of a 500 (R10). The question cannot be
            // asked again here: the command bus runs the handler in a transaction the failure has
            // already aborted, so the answer comes from the constraint PostgreSQL named.
            if (EmailUniqueness::isEmailCollision($exception)) {
                throw EmailAlreadyUsedException::forEmail($command->email);
            }

            throw $exception;
        }
    }

    private function emailTaken(string $email): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM customer_profile WHERE lower(email) = lower(:email)',
            ['email' => $email],
        );
    }
}
