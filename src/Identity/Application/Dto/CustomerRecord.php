<?php

declare(strict_types=1);

namespace App\Identity\Application\Dto;

final readonly class CustomerRecord
{
    public function __construct(
        public string $id,
        public string $email,
        public string $role,
        public string $status,
        public ?string $googleId,
    ) {
    }
}
