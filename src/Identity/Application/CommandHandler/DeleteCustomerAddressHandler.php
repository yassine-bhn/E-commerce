<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\DeleteCustomerAddress;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class DeleteCustomerAddressHandler
{
    public function __construct(private Connection $connection)
    {
    }

    #[AsMessageHandler(bus: 'command.bus')]
    public function __invoke(DeleteCustomerAddress $command): void
    {
        $this->connection->executeStatement(
            'DELETE FROM customer_address WHERE id = :id AND customer_id = :customer',
            ['id' => $command->id, 'customer' => $command->customerId],
        );
    }
}
