<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Shared\Application\Pagination\CursorRequest;

/**
 * One keyset page of the authenticated customer's orders (R7).
 *
 * `status` uses the storefront vocabulary (`en_preparation`, `expediee`, `annulee`), the dates
 * are inclusive calendar days, and `q` matches the order number.
 */
final readonly class ListOrdersForCustomer
{
    public const int DEFAULT_LIMIT = 20;
    public const int MAX_LIMIT = 100;

    public function __construct(
        public string $customerId,
        public CursorRequest $cursor = new CursorRequest(self::DEFAULT_LIMIT, null, self::MAX_LIMIT),
        public ?string $status = null,
        public ?\DateTimeImmutable $dateFrom = null,
        public ?\DateTimeImmutable $dateTo = null,
        public ?string $q = null,
    ) {
    }
}
