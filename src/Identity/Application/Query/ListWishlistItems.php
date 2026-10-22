<?php

declare(strict_types=1);

namespace App\Identity\Application\Query;

use App\Shared\Application\Pagination\PageRequest;

final readonly class ListWishlistItems
{
    public const int DEFAULT_SIZE = 24;
    public const int MAX_SIZE = 100;

    /** @var list<string> */
    public const array SORTS = ['recent', 'name', 'price_asc', 'price_desc'];

    public function __construct(
        public string $customerId,
        public PageRequest $page = new PageRequest(1, self::DEFAULT_SIZE, self::MAX_SIZE),
        public ?string $q = null,
        public ?string $sort = null,
    ) {
    }

    public function effectiveSort(): string
    {
        return \in_array($this->sort, self::SORTS, true) ? $this->sort : 'recent';
    }
}
