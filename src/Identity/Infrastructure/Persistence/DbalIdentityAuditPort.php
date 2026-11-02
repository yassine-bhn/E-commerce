<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Persistence;

use App\Identity\Application\Port\IdentityAuditPort;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * DIP-003 — R14: one audit row per admin action, the actor and the reason recorded together.
 */
final class DbalIdentityAuditPort implements IdentityAuditPort
{
    public function __construct(private Connection $connection)
    {
    }

    public function record(
        string $action,
        string $targetUserId,
        string $actorAdminId,
        ?string $reason = null,
    ): void {
        $this->connection->executeStatement(
            'INSERT INTO identity_audit_log (id, action, target_user_id, actor_admin_id, reason, created_at)
             VALUES (:id, :action, :target_user_id, :actor_admin_id, :reason, :created_at)',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'action' => $action,
                'target_user_id' => $targetUserId,
                'actor_admin_id' => $actorAdminId,
                'reason' => $reason,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP'),
            ],
        );
    }
}
