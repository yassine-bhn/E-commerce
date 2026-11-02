<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\UpdateCustomerProfile;
use App\Identity\Application\Dto\CustomerProfileData;
use App\Identity\Application\Port\CustomerProfileStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class UpdateCustomerProfileHandler
{
    public function __construct(private CustomerProfileStore $profiles)
    {
    }

    #[AsMessageHandler(bus: 'command.bus')]
    public function __invoke(UpdateCustomerProfile $command): void
    {
        $this->profiles->upsert(new CustomerProfileData(
            id: $command->customerId,
            name: $command->name,
            email: $command->email,
            phone: $command->phone,
            accountType: $command->accountType,
            company: $command->company,
            mf: $command->mf,
        ));
    }
}
