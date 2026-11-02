<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

/**
 * DIP-003 — RG3.4: the delete path needs to know whether financial history must be preserved.
 */
interface CustomerDeletionReader
{
    public function orderCount(string $customerId): int;
}
