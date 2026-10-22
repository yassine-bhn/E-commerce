<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Shared\Application\Pagination\PageRequest;

/**
 * R11 — the dashboard brand list: one bounded offset page with the documented free-text filter
 * and sorts (spec row 11: 50 per page, 200 max).
 */
final readonly class ListAdminBrands
{
    public const int DEFAULT_SIZE = 50;
    public const int MAX_SIZE = 200;

    /** @var list<string> */
    public const array SORTS = ['name', 'productCount'];

    public function __construct(
        public PageRequest $page = new PageRequest(1, self::DEFAULT_SIZE, self::MAX_SIZE),
        public ?string $q = null,
        public ?string $sort = null,
    ) {
    }

    public function effectiveSort(): string
    {
        return \in_array($this->sort, self::SORTS, true) ? $this->sort : 'name';
    }
}
