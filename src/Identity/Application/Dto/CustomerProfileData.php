<?php

declare(strict_types=1);

namespace App\Identity\Application\Dto;

final readonly class CustomerProfileData
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public string $phone,
        public string $accountType,
        public ?string $company,
        public ?string $mf,
    ) {
    }
}
