<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Audit;

use App\Catalog\Application\Port\CatalogAuditPort;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * R44 — the append-only trail of the approval workflow.
 *
 * `identity_audit_log` is the platform's audit table but its target is a UUID user id, so it
 * cannot name a product or category slug without lying about its own column (ADR-0012). This
 * writer keeps the same append-only shape in a catalog-owned table, written through plain DBAL so
 * it joins whatever transaction the command handler opened.
 */
final class DbalCatalogAuditWriter implements CatalogAuditPort
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function record(
        string $action,
        string $targetType,
        string $targetSlug,
        string $actorId,
        ?string $reason = null,
    ): void {
        $this->connection->executeStatement(
            'INSERT INTO catalog_audit_log (id, action, target_type, target_slug, actor_id, reason, created_at)
             VALUES (:id, :action, :target_type, :target_slug, :actor_id, :reason, :created_at)',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'action' => $action,
                'target_type' => $targetType,
                'target_slug' => $targetSlug,
                'actor_id' => $actorId,
                'reason' => $reason,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP'),
            ],
        );
    }
}
