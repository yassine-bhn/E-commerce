<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

final readonly class UnbanCustomer
{
    public function __construct(
        public string $customerId,
        public string $adminId,
        public string $reason,
    ) {
    }
}
