<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

/**
 * R18/R22 — drop one slide from the banner set. Only the reference is removed: the uploaded file
 * stays exactly where the dashboard upload endpoint put it.
 */
final readonly class BannerRemoveImage
{
    public function __construct(
        public string $imageId,
    ) {
    }
}
