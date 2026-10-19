<?php

declare(strict_types=1);

namespace App\Identity\Domain\Model;

final class EmailAlreadyUsedException extends \RuntimeException
{
    public static function forEmail(string $email): self
    {
        return new self(sprintf('An account already exists for the email "%s".', $email));
    }
}
