<?php

declare(strict_types=1);

namespace App\Identity\Domain\Model;

/**
 * R11 — the dashboard may assign exactly the two roles named in the brief; anything else is
 * refused before any write (ERR-002: domain exception, mapped to 4xx in Infrastructure).
 */
final class RoleNotAssignableException extends \DomainException
{
    public static function forRole(string $role): self
    {
        return new self(sprintf(
            'Only %s can be assigned to a user. Received: "%s".',
            AssignableCustomerRole::sentence(),
            $role,
        ));
    }
}
