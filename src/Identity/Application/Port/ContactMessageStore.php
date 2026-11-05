<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

/**
 * DIP-003 — the public contact form's intake write.
 */
interface ContactMessageStore
{
    public function store(
        string $id,
        string $name,
        string $email,
        ?string $phone,
        ?string $subject,
        string $message,
    ): void;
}
