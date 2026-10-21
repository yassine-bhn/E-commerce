<?php

declare(strict_types=1);

namespace App\Identity\Domain\Model;

/**
 * SEC-002 / QRY-004 — the admin user list authorizes the requester on the read side itself, so a
 * caller that reaches the finder without an admin role gets a domain refusal, not a row set.
 */
final class AdminAccessRequiredException extends \DomainException
{
    public static function create(): self
    {
        return new self('Only an administrator account may list user accounts.');
    }
}
