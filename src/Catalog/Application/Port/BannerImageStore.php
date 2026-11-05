<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Model\BannerSet;

/**
 * DIP-003 — the banner slide writes: replace-all, dense reorder, single removal; each touches
 * the banner's updated_at in the same transaction the middleware opened.
 */
interface BannerImageStore
{
    public function replace(BannerSet $set): void;

    public function reorder(BannerSet $set): void;

    public function remove(string $imageId): void;
}
