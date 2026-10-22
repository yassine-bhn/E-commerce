<?php

declare(strict_types=1);

namespace App\Shared\Application\Pagination;

final readonly class CursorRequest
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT = 100;

    public function __construct(
        public int $limit = self::DEFAULT_LIMIT,
        public ?string $cursor = null,
        public ?int $maxLimit = null,
    ) {
    }

    public function effectiveLimit(): int
    {
        return min($this->maxLimit ?? self::MAX_LIMIT, max(1, $this->limit));
    }

    public function rawCursor(): ?string
    {
        if (null === $this->cursor || '' === trim($this->cursor)) {
            return null;
        }

        return $this->cursor;
    }
}
