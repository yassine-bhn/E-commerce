<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

/**
 * R20 — put the existing slides in a new order without changing which images are in the set.
 */
final readonly class BannerReorderImages
{
    /**
     * @param list<string> $imageIds every banner image id, exactly once, in the new order
     */
    public function __construct(
        public array $imageIds,
    ) {
    }
}
