<?php

declare(strict_types=1);

namespace App\Identity\Domain\Model;

/**
 * R13 / RG3.6 — the role editor may never leave the platform without an active SuperAdmin.
 * Same client-facing message as the delete guard (LastSuperAdminCannotBeRemovedException) so the
 * dashboard shows one contract for both paths; enforced in the handler, never the controller.
 */
final class LastSuperAdminCannotBeDemotedException extends \DomainException
{
    public static function create(): self
    {
        return new self('The platform must retain at least one active SuperAdmin account.');
    }
}
