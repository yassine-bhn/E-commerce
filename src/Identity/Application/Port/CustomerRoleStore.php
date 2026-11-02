<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

/**
 * DIP-003 — the role write on customer_profile (R14 audits the change, the store writes it).
 */
interface CustomerRoleStore
{
    public function changeRole(string $id, string $role): void;
}
