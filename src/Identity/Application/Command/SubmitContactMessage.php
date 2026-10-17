<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

final readonly class SubmitContactMessage
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public ?string $phone,
        public ?string $subject,
        public string $message,
    ) {
    }
}
