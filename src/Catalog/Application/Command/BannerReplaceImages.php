<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

/**
 * R18 — replace the whole ordered image set of the single site banner. One call grows the set,
 * shrinks it and reorders it, so the dashboard can never observe a half-applied banner.
 */
final readonly class BannerReplaceImages
{
    /**
     * @param list<string> $imagePaths dashboard upload references, in the order the admin selected
     */
    public function __construct(
        public array $imagePaths,
    ) {
    }
}
