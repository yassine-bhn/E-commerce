<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\ReadModel;

use App\Ordering\Application\Query\GetOrderAuditTrail;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * solid Task 4.7 — the order's status trail through the query bus, in version order.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class GetOrderAuditTrailHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<array{from_status: string|null, to_status: string, from_version: int, to_version: int, changed_by: string, reason: string, created_at: string}>
     */
    public function __invoke(GetOrderAuditTrail $query): array
    {
        /** @var list<array{from_status: string|null, to_status: string, from_version: int, to_version: int, changed_by: string, reason: string, created_at: string}> $logs */
        $logs = $this->connection->fetchAllAssociative(
            'SELECT from_status, to_status, from_version, to_version, changed_by, reason, created_at
             FROM ordering_order_audit_trail
             WHERE order_id = :id
             ORDER BY to_version ASC',
            ['id' => $query->orderId],
        );

        return $logs;
    }
}
