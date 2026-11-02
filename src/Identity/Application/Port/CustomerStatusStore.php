<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

/**
 * DIP-003 — the ban/unban writes on customer_profile.
 */
interface CustomerStatusStore
{
    public function ban(string $id, string $reason, string $adminId): void;

    public function unban(string $id): void;
}
