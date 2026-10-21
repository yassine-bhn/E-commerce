<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

/**
 * WP3 / R10 — task-based command setting a user's role (CMD-001, CMD-002, CMD-003).
 */
final readonly class ChangeCustomerRole
{
    public function __construct(
        public string $customerId,
        public string $adminId,
        public string $role,
    ) {
    }
}
