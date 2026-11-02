<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\RegisterCustomer;
use App\Identity\Application\Dto\NewCustomer;
use App\Identity\Application\Port\CustomerRegistrar;
use App\Identity\Domain\Model\EmailAlreadyUsedException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class RegisterCustomerHandler
{
    public function __construct(
        private CustomerRegistrar $registrar,
    ) {
    }

    public function __invoke(RegisterCustomer $command): void
    {
        if ($this->registrar->emailExists($command->email)) {
            throw EmailAlreadyUsedException::forEmail($command->email);
        }

        $this->registrar->register(new NewCustomer(
            id: $command->id,
            name: $command->name,
            email: $command->email,
            phone: $command->phone,
            accountType: $command->accountType,
            company: $command->company,
            mf: $command->mf,
            passwordHash: password_hash($command->password, PASSWORD_BCRYPT),
        ));
    }
}
