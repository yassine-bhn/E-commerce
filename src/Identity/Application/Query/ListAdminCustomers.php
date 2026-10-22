<?php

declare(strict_types=1);

namespace App\Identity\Application\Query;

use App\Shared\Application\Pagination\PageRequest;

/**
 * R15 — dashboard user list. Carries the requester identity so the finder can authorize inside the
 * query itself (QRY-004 / SEC-002); page/size are raw client input and are clamped by the shared
 * PageRequest (spec: `page`/`size`, size capped at 50, with free-text, role and status filters).
 */
final readonly class ListAdminCustomers
{
    public const int DEFAULT_SIZE = 20;
    public const int MAX_SIZE = 50;

    /** @var list<string> */
    public const array SORTS = ['name', 'createdAt', 'orderCount'];

    public function __construct(
        public string $requesterId,
        public bool $requesterIsAdmin,
        public PageRequest $page = new PageRequest(1, self::DEFAULT_SIZE, self::MAX_SIZE),
        public ?string $q = null,
        public ?string $role = null,
        public ?string $status = null,
        public ?string $sort = null,
    ) {
    }

    public function effectiveSort(): string
    {
        return \in_array($this->sort, self::SORTS, true) ? $this->sort : 'name';
    }
}
