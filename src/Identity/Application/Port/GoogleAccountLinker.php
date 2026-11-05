<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

/**
 * DIP-003 — the Google sign-in write path. A lost address race is not an error: the next
 * sign-in links the Google identity through the normal path (spec-run-12 R7).
 */
interface GoogleAccountLinker
{
    public function isLinked(string $googleId): bool;

    /**
     * Attaches the Google identity to the account that already owns the address; answers
     * whether such an account exists.
     */
    public function linkByEmail(string $email, string $googleId): bool;

    public function register(string $id, string $name, string $email, string $googleId): void;
}
