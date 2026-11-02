<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\SaveCustomerAddress;
use App\Identity\Application\Dto\AddressData;
use App\Identity\Application\Port\CustomerAddressStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class SaveCustomerAddressHandler
{
    public function __construct(private CustomerAddressStore $addresses)
    {
    }

    #[AsMessageHandler(bus: 'command.bus')]
    public function __invoke(SaveCustomerAddress $command): void
    {
        $this->addresses->save(new AddressData(
            id: $command->id,
            customerId: $command->customerId,
            name: $command->name,
            phone: $command->phone,
            street: $command->street,
            city: $command->city,
            governorate: $command->governorate,
            zip: $command->zip,
        ));
    }
}
