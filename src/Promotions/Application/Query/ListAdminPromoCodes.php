<?php

declare(strict_types=1);

namespace App\Promotions\Application\Query;

use App\Shared\Application\Pagination\PageRequest;

/**
 * R12 — the dashboard promo-code list: one bounded offset page with the documented free-text
 * and active filters in the fixed `code` order (spec row 12: 50 per page, 200 max).
 */
final readonly class ListAdminPromoCodes
{
    public const int DEFAULT_SIZE = 50;
    public const int MAX_SIZE = 200;

    public function __construct(
        public PageRequest $page = new PageRequest(1, self::DEFAULT_SIZE, self::MAX_SIZE),
        public ?string $q = null,
        public ?bool $active = null,
    ) {
    }
}
