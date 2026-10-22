<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Shared\Application\Pagination\PageRequest;

/**
 * The category list the admin dashboard edits (spec R8/R10): every category, hidden ones
 * included, with its sub-levels and product count — now a bounded offset page with the
 * documented filters and sorts (spec row 10: 50 per page, 200 max).
 *
 * Like every storefront read in this repository it carries the requester identity and the
 * resolved role, and {@see \App\Catalog\Infrastructure\ReadModel\CategoryFinder} turns them
 * into the rows the database is allowed to hand back (QRY-004).
 */
final readonly class ListCategories
{
    public const int DEFAULT_SIZE = 50;
    public const int MAX_SIZE = 200;

    /** @var list<string> */
    public const array SORTS = ['sortOrder', 'label', 'productCount'];

    public function __construct(
        public string $requesterId,
        public bool $isAdmin,
        public PageRequest $page = new PageRequest(1, self::DEFAULT_SIZE, self::MAX_SIZE),
        public ?string $q = null,
        public ?bool $hidden = null,
        public ?string $parent = null,
        public ?string $sort = null,
    ) {
    }

    public function effectiveSort(): string
    {
        return \in_array($this->sort, self::SORTS, true) ? $this->sort : 'sortOrder';
    }
}
