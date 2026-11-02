<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

/**
 * DIP-003 — the password half of a reset: look the addressee up, write the new hash.
 */
interface PasswordResetStore
{
    /** @return array{id: string, email: string}|null */
    public function customerByEmail(string $email): ?array;

    public function updatePassword(string $customerId, string $passwordHash): void;
}
