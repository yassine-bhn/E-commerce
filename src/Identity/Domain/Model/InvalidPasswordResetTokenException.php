<?php

declare(strict_types=1);

namespace App\Identity\Domain\Model;

final class InvalidPasswordResetTokenException extends \RuntimeException
{
    public static function invalid(): self
    {
        return new self('This password reset token is invalid or has expired.');
    }
}
