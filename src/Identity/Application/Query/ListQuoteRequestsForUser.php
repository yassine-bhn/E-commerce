<?php

declare(strict_types=1);

namespace App\Identity\Application\Query;

use App\Shared\Application\Pagination\CursorRequest;

/**
 * One keyset page of the authenticated user's quote requests (R8).
 *
 * `q` matches the category, the city or the message; the dates are inclusive calendar days.
 */
final readonly class ListQuoteRequestsForUser
{
    public const int DEFAULT_LIMIT = 20;
    public const int MAX_LIMIT = 100;

    public function __construct(
        public string $userId,
        public CursorRequest $cursor = new CursorRequest(self::DEFAULT_LIMIT, null, self::MAX_LIMIT),
        public ?string $status = null,
        public ?\DateTimeImmutable $dateFrom = null,
        public ?\DateTimeImmutable $dateTo = null,
        public ?string $q = null,
    ) {
    }
}
