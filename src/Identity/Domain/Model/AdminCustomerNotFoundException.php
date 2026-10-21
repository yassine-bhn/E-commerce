<?php

declare(strict_types=1);

namespace App\Identity\Domain\Model;

/**
 * WP3 — the role editor only targets an existing account; mapping this to 404 happens in
 * Infrastructure (ERR-002) so a role write never creates a phantom audit row.
 */
final class AdminCustomerNotFoundException extends \DomainException
{
    public static function forCustomer(string $customerId): self
    {
        return new self(sprintf('The selected user account does not exist. Given: "%s".', $customerId));
    }
}
