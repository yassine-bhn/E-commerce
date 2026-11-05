<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Support;

use App\Identity\Application\Dto\CustomerRecord;
use App\Identity\Application\Port\CustomerAccountReader;
use App\Identity\Application\Port\CustomerDeletionReader;
use App\Identity\Application\Port\CustomerDeletionWriter;
use App\Identity\Application\Port\CustomerRoleStore;
use App\Identity\Application\Port\CustomerStatusStore;

/**
 * Inline fake (TDD-008) of the customer-account ports: it answers the pinned account state and
 * records every transition so a handler test asserts on outcomes, not on SQL.
 */
final class InMemoryCustomerAccounts implements CustomerAccountReader, CustomerDeletionReader, CustomerDeletionWriter, CustomerRoleStore, CustomerStatusStore
{
    public ?CustomerRecord $record = null;

    public int $activeSuperAdmins = 2;

    public int $orders = 0;

    /** @var list<array{customerId: string, reason: string, adminId: string}> */
    public array $bans = [];

    /** @var list<string> */
    public array $unbans = [];

    /** @var list<array{customerId: string, role: string}> */
    public array $roleChanges = [];

    /** @var list<array{customerId: string, anonEmail: string}> */
    public array $anonymizations = [];

    /** @var list<string> */
    public array $hardDeletes = [];

    public function find(string $id): ?CustomerRecord
    {
        return $this->record;
    }

    public function activeSuperAdminCount(): int
    {
        return $this->activeSuperAdmins;
    }

    public function orderCount(string $customerId): int
    {
        return $this->orders;
    }

    public function ban(string $id, string $reason, string $adminId): void
    {
        $this->bans[] = ['customerId' => $id, 'reason' => $reason, 'adminId' => $adminId];
    }

    public function unban(string $id): void
    {
        $this->unbans[] = $id;
    }

    public function changeRole(string $id, string $role): void
    {
        $this->roleChanges[] = ['customerId' => $id, 'role' => $role];
    }

    public function anonymize(string $id, string $anonEmail): void
    {
        $this->anonymizations[] = ['customerId' => $id, 'anonEmail' => $anonEmail];
    }

    public function hardDelete(string $id): void
    {
        $this->hardDeletes[] = $id;
    }
}
