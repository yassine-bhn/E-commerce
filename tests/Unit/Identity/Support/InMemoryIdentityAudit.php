<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Support;

use App\Identity\Application\Port\IdentityAuditPort;

/**
 * Inline fake (TDD-008) of the audit port: rows are recorded for the handler assertions.
 *
 * @phpstan-type AuditRow array{action: string, targetUserId: string, actorAdminId: string, reason: ?string}
 */
final class InMemoryIdentityAudit implements IdentityAuditPort
{
    /** @var list<AuditRow> */
    public array $rows = [];

    public function record(
        string $action,
        string $targetUserId,
        string $actorAdminId,
        ?string $reason = null,
    ): void {
        $this->rows[] = [
            'action' => $action,
            'targetUserId' => $targetUserId,
            'actorAdminId' => $actorAdminId,
            'reason' => $reason,
        ];
    }
}
