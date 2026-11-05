<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

/**
 * DIP-003 — RG3.4's two outcomes: anonymize the profile (orders exist) or hard-delete it.
 */
interface CustomerDeletionWriter
{
    public function anonymize(string $id, string $anonEmail): void;

    public function hardDelete(string $id): void;
}
