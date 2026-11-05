<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Persistence;

use App\Identity\Application\Dto\AddressData;
use App\Identity\Application\Port\CustomerAddressStore;
use Doctrine\DBAL\Connection;

/**
 * DIP-003 — the saved-address adapter; the SQL is the profile handler's former inline writes.
 */
final class DbalCustomerAddressStore implements CustomerAddressStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(AddressData $address): void
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
                'id' => $address->id,
                'customer' => $address->customerId,
                'name' => $address->name,
                'phone' => $address->phone,
                'street' => $address->street,
                'city' => $address->city,
                'gov' => $address->governorate,
                'zip' => $address->zip,
            ],
        );
    }

    public function delete(string $id, string $customerId): void
    {
        $this->connection->executeStatement(
            'DELETE FROM customer_address WHERE id = :id AND customer_id = :customer',
            ['id' => $id, 'customer' => $customerId],
        );
    }
}
