<?php

declare(strict_types=1);

namespace App\Identity\Application\Dto;

final readonly class GoogleUser
{
    public function __construct(
        public string $googleId,
        public string $email,
        public string $name,
    ) {
    }
}
