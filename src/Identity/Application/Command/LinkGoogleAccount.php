<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

final readonly class LinkGoogleAccount
{
    public function __construct(
        public string $googleId,
        public string $email,
        public string $name,
    ) {
    }
}
