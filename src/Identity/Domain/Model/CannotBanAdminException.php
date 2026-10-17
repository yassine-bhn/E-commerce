<?php

declare(strict_types=1);

namespace App\Identity\Domain\Model;

final class CannotBanAdminException extends \DomainException
{
    public static function forRole(string $role): self
    {
        return new self('Admins and SuperAdmins cannot be banned by standard administrative action.');
    }
}
