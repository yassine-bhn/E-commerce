<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\UpdateCustomerProfile;
use App\Identity\Application\EmailUniqueness;
use App\Identity\Domain\Model\EmailAlreadyUsedException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class UpdateCustomerProfileHandler
{
    public function __construct(private Connection $connection)
    {
    }

    #[AsMessageHandler(bus: 'command.bus')]
    public function __invoke(UpdateCustomerProfile $command): void
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
                    'id' => $command->customerId,
                    'name' => $command->name,
                    'email' => $command->email,
                    'phone' => $command->phone,
                    'type' => $command->accountType,
                    'company' => $command->company,
                    'mf' => $command->mf,
                ],
            );
        } catch (UniqueConstraintViolationException $exception) {
            // The profile update writes the address the customer typed. Since Version20261024000000
            // two accounts cannot share one case-insensitively (spec-run-12 R7), so claiming an
            // address another account owns is the same conflict registration reports (R10) — not
            // the 500 a bare unique violation would become.
            if (EmailUniqueness::isEmailCollision($exception)) {
                throw EmailAlreadyUsedException::forEmail($command->email);
            }

            throw $exception;
        }
    }
}
