<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Model\BannerSet;

/**
 * DIP-003 — the banner as it is stored right now, in the order every read returns it (R20).
 */
interface BannerSetReader
{
    public function current(): BannerSet;
}
