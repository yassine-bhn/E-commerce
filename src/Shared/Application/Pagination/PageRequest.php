<?php

declare(strict_types=1);

namespace App\Shared\Application\Pagination;

final readonly class PageRequest
{
    public const DEFAULT_SIZE = 20;
    public const MAX_SIZE = 100;

    public function __construct(
        public int $page = 1,
        public int $size = self::DEFAULT_SIZE,
        public ?int $maxSize = null,
    ) {
    }

    public function effectivePage(): int
    {
        return max(1, $this->page);
    }

    public function effectiveSize(): int
    {
        return min($this->maxSize ?? self::MAX_SIZE, max(1, $this->size));
    }

    public function effectiveOffset(): int
    {
        return ($this->effectivePage() - 1) * $this->effectiveSize();
    }
}
