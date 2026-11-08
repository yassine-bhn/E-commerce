<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\ChangeCustomerPassword;
use App\Identity\Application\Port\PasswordResetStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class ChangeCustomerPasswordHandler
{
    public function __construct(private PasswordResetStore $passwords)
    {
    }

    public function __invoke(ChangeCustomerPassword $command): void
    {
        $hash = $this->passwords->passwordHashForCustomer($command->customerId);
        if (null === $hash || !password_verify($command->currentPassword, $hash)) {
            throw new \InvalidArgumentException('The current password is incorrect.');
        }

        $this->passwords->updatePassword($command->customerId, password_hash($command->newPassword, PASSWORD_BCRYPT));
    }
}
