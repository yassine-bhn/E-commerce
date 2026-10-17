<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\ReadModel;

use App\Identity\Application\Query\GetCustomerProfile;
use App\Identity\Application\Query\ListCustomerAddresses;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class CustomerProfileFinder
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array<string, mixed>|null */
    #[AsMessageHandler(bus: 'query.bus')]
    public function handleGetProfile(GetCustomerProfile $query): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, name, email, phone, account_type, company, mf
             FROM customer_profile WHERE id = :id',
            ['id' => $query->customerId],
        );

        if (false === $row) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'phone' => (string) ($row['phone'] ?? ''),
            'type' => (string) $row['account_type'],
            'company' => $row['company'],
            'mf' => $row['mf'],
        ];
    }

    /** @return list<array<string, mixed>> */
    #[AsMessageHandler(bus: 'query.bus')]
    public function handleListAddresses(ListCustomerAddresses $query): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, name, phone, street, city, governorate, zip
             FROM customer_address WHERE customer_id = :customer
             ORDER BY created_at ASC',
            ['customer' => $query->customerId],
        );

        return array_map(static fn (array $r): array => [
            'id' => (string) $r['id'],
            'name' => (string) $r['name'],
            'phone' => (string) $r['phone'],
            'street' => (string) $r['street'],
            'city' => (string) $r['city'],
            'governorate' => (string) $r['governorate'],
            'zip' => (string) $r['zip'],
        ], $rows);
    }
}
