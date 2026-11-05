<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\DeleteCustomerAddress;
use App\Identity\Application\Port\CustomerAddressStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class DeleteCustomerAddressHandler
{
    public function __construct(private CustomerAddressStore $addresses)
    {
    }

    #[AsMessageHandler(bus: 'command.bus')]
    public function __invoke(DeleteCustomerAddress $command): void
    {
        $this->addresses->delete($command->id, $command->customerId);
    }
}
