<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

/**
 * DIP-003 — the token half of a reset: one active token per customer, consumed exactly once.
 */
interface PasswordResetTokenStore
{
    public function invalidateActive(string $customerId): void;

    public function issue(string $customerId, string $tokenHash): void;

    /**
     * Marks the token used and answers the customer it belongs to; null means the token is
     * unknown, already used or expired.
     */
    public function consume(string $tokenHash): ?string;
}
