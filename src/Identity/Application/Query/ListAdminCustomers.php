<?php

declare(strict_types=1);

namespace App\Identity\Application\Query;

/**
 * R15 — dashboard user list. Carries the requester identity so the finder can authorize inside
 * the query itself (QRY-004 / SEC-002); page/limit are raw client input and are clamped by the
 * effective* accessors (spec Q11: page/limit, limit capped at 50, no free-text or role filters).
 */
final readonly class ListAdminCustomers
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT = 50;

    public function __construct(
        public string $requesterId,
        public bool $requesterIsAdmin,
        public int $page = 1,
        public int $limit = self::DEFAULT_LIMIT,
    ) {
    }

    public function effectivePage(): int
    {
        return max(1, $this->page);
    }

    public function effectiveLimit(): int
    {
        return min(self::MAX_LIMIT, max(1, $this->limit));
    }

    public function effectiveOffset(): int
    {
        return ($this->effectivePage() - 1) * $this->effectiveLimit();
    }
}
