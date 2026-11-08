<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

final readonly class ChangeCustomerPassword
{
    public function __construct(
        public string $customerId,
        public string $currentPassword,
        public string $newPassword,
    ) {
    }
}
