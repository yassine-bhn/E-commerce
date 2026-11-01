<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\SaveCustomerAddress;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class SaveCustomerAddressHandler
{
    public function __construct(private Connection $connection)
    {
    }

    #[AsMessageHandler(bus: 'command.bus')]
    public function __invoke(SaveCustomerAddress $command): void
    {
        $this->connection->executeStatement(
            'INSERT INTO customer_address (id, customer_id, name, phone, street, city, governorate, zip)
             VALUES (:id, :customer, :name, :phone, :street, :city, :gov, :zip)
             ON CONFLICT (id) DO UPDATE SET
                name = EXCLUDED.name,
                phone = EXCLUDED.phone,
                street = EXCLUDED.street,
                city = EXCLUDED.city,
                governorate = EXCLUDED.governorate,
                zip = EXCLUDED.zip',
            [
                'id' => $command->id,
                'customer' => $command->customerId,
                'name' => $command->name,
                'phone' => $command->phone,
                'street' => $command->street,
                'city' => $command->city,
                'gov' => $command->governorate,
                'zip' => $command->zip,
            ],
        );
    }
}
