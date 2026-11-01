<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\ReadModel;

use App\Identity\Application\Query\GetCustomerProfile;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class GetCustomerProfileHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array<string, mixed>|null */
    #[AsMessageHandler(bus: 'query.bus')]
    public function __invoke(GetCustomerProfile $query): ?array
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
}
