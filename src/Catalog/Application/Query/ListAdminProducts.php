<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Shared\Application\Pagination\PageRequest;

/**
 * Dashboard list of every catalog product whatever its approval or publication state
 * (the public list only shows approved, published rows), with filters, sort and pagination.
 *
 * Carries the requester identity so the finder authorizes inside the query itself
 * (QRY-004 / SEC-002): reading products in every state belongs to the back office (R15).
 */
final readonly class ListAdminProducts
{
    public const int DEFAULT_SIZE = 20;
    public const int MAX_SIZE = 50;

    /** @var list<string> */
    public const array SORTS = ['name', 'priceAsc', 'priceDesc', 'stockAsc', 'recent', 'approval'];

    /**
     * @param string|null $approval pending, approved or rejected
     */
    public function __construct(
        public string $requesterId,
        public bool $requesterIsAdmin,
        public PageRequest $page = new PageRequest(1, self::DEFAULT_SIZE, self::MAX_SIZE),
        public ?string $q = null,
        public ?string $category = null,
        public ?string $subcategory = null,
        public ?string $brand = null,
        public ?string $stock = null,
        public ?string $approval = null,
        public ?bool $published = null,
        public ?string $sort = null,
    ) {
    }

    public function effectiveSort(): string
    {
        return \in_array($this->sort, self::SORTS, true) ? $this->sort : 'name';
    }
}
