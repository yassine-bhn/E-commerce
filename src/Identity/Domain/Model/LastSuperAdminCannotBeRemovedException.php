<?php

declare(strict_types=1);

namespace App\Identity\Domain\Model;

final class LastSuperAdminCannotBeRemovedException extends \DomainException
{
    public static function create(): self
    {
        return new self('The platform must retain at least one active SuperAdmin account.');
    }
}
