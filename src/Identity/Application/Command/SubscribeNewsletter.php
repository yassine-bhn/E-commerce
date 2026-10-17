<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

final readonly class SubscribeNewsletter
{
    public function __construct(
        public string $id,
        public string $email,
    ) {
    }
}
