<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

/**
 * DIP-003 — the newsletter subscribe write; a repeat subscribe is a no-op.
 */
interface NewsletterStore
{
    public function subscribe(string $id, string $email): void;
}
