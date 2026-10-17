<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

final readonly class UpdateCustomerProfile
{
    public function __construct(
        public string $customerId,
        public string $name,
        public string $email,
        public string $phone,
        public string $accountType = 'particulier',
        public ?string $company = null,
        public ?string $mf = null,
    ) {
    }
}
