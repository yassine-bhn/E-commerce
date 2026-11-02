<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

use App\Identity\Application\Dto\CustomerRecord;

/**
 * DIP-003 — reads one account row for the admin guards (ban, role change, delete).
 */
interface CustomerAccountReader
{
    public function find(string $id): ?CustomerRecord;

    /**
     * R13 — an "active" super admin is status != 'banned'; the same predicate the delete guard
     * uses so both guards agree on the account set.
     */
    public function activeSuperAdminCount(): int;
}
