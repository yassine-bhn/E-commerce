<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

/**
 * Admin dashboard read of one customer's orders (R16).
 *
 * Deliberately separate from ListOrdersForCustomer, which carries no requester identity and is
 * therefore only safe for the customer-scoped storefront route (QRY-004).
 */
final readonly class ListOrdersForAdminRequestedCustomer
{
    public function __construct(
        public string $requesterId,
        public bool $requesterIsAdmin,
        public string $customerId,
    ) {
    }
}
