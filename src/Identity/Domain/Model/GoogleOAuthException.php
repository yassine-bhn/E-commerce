<?php

declare(strict_types=1);

namespace App\Identity\Domain\Model;

final class GoogleOAuthException extends \RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
