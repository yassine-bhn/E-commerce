<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Shared\Application\Pagination\CursorRequest;

/**
 * One keyset page of one customer's orders as read by the admin dashboard (R16).
 *
 * Deliberately separate from ListOrdersForCustomer, which carries no requester identity and is
 * therefore only safe for the customer-scoped storefront route (QRY-004).
 *
 * `status` uses the storefront vocabulary (`en_preparation`, `expediee`, `annulee`), the dates
 * are inclusive calendar days, and `q` matches the order number.
 */
final readonly class ListOrdersForAdminRequestedCustomer
{
    public const int DEFAULT_LIMIT = 20;
    public const int MAX_LIMIT = 100;

    public function __construct(
        public string $requesterId,
        public bool $requesterIsAdmin,
        public string $customerId,
        public CursorRequest $cursor = new CursorRequest(self::DEFAULT_LIMIT, null, self::MAX_LIMIT),
        public ?string $status = null,
        public ?\DateTimeImmutable $dateFrom = null,
        public ?\DateTimeImmutable $dateTo = null,
        public ?string $q = null,
    ) {
    }
}
