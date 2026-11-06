<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\ReadModel;

use App\Identity\Application\Query\GetCustomerAuditTrail;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * solid Task 4.6 — R14's trail through the query bus: the rows the admin route used to fetch
 * inline, ordered oldest first.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class GetCustomerAuditTrailHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<array{action: string, actor_admin_id: string, reason: string|null, created_at: string}>
     */
    public function __invoke(GetCustomerAuditTrail $query): array
    {
        /** @var list<array{action: string, actor_admin_id: string, reason: string|null, created_at: string}> $logs */
        $logs = $this->connection->fetchAllAssociative(
            'SELECT action, actor_admin_id, reason, created_at
             FROM identity_audit_log
             WHERE target_user_id = :id
             ORDER BY created_at ASC',
            ['id' => $query->customerId],
        );

        return $logs;
    }
}
