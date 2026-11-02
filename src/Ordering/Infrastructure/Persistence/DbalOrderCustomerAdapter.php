<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence;

use App\Ordering\Application\Dto\OrderCustomer;
use App\Ordering\Application\Port\OrderCustomerReader;
use App\Ordering\Domain\Model\OrderStatus;
use Doctrine\DBAL\Connection;

/**
 * DIP-003 — the owner/status read behind the customer-cancel guard (the state reader and this
 * port both declare `find`, so one class cannot implement the two contracts).
 */
final class DbalOrderCustomerAdapter implements OrderCustomerReader
{
    public function __construct(private Connection $connection)
    {
    }

    public function find(string $orderId): ?OrderCustomer
    {
        $row = $this->connection->fetchAssociative(
            'SELECT customer_id, status FROM ordering_orders WHERE id = :id',
            ['id' => $orderId],
        );

        if (false === $row) {
            return null;
        }

        return new OrderCustomer(
            customerId: (string) $row['customer_id'],
            status: OrderStatus::from((string) $row['status']),
        );
    }
}
