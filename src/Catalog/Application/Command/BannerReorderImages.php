<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

/**
 * R20 — put the existing slides in a new order without changing which images are in the set.
 *
 * solid Task 4.4 — the raw dashboard body rides the command; BannerPayload validates its shape
 * inside the handler.
 */
final class BannerReorderImages
{
    public function __construct(
        public mixed $body,
    ) {
    }
}
