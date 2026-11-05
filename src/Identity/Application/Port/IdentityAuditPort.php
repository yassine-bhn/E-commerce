<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

/**
 * DIP-003 — R14: admin actions on an account are audited with the actor and the reason.
 */
interface IdentityAuditPort
{
    public function record(
        string $action,
        string $targetUserId,
        string $actorAdminId,
        ?string $reason = null,
    ): void;
}
