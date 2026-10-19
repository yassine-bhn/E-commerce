<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

final readonly class RegisterCustomer
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public string $password,
        public string $phone,
        public string $accountType = 'particulier',
        public ?string $company = null,
        public ?string $mf = null,
    ) {
    }
}
