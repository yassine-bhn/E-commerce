<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\RegisterCustomer;
use App\Identity\Domain\Model\EmailAlreadyUsedException;
use Doctrine\DBAL\Connection;
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
        $existing = $this->connection->fetchOne(
            'SELECT 1 FROM customer_profile WHERE lower(email) = lower(:email)',
            ['email' => $command->email],
        );

        if (false !== $existing) {
            throw EmailAlreadyUsedException::forEmail($command->email);
        }

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
    }
}
