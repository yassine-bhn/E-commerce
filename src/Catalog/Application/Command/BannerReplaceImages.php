<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

/**
 * R18 — replace the whole ordered image set of the single site banner. One call grows the set,
 * shrinks it and reorders it, so the dashboard can never observe a half-applied banner.
 *
 * solid Task 4.4 — the raw dashboard body rides the command; BannerPayload validates its shape
 * inside the handler, so the controller stays HTTP↔bus only.
 */
final class BannerReplaceImages
{
    public function __construct(
        public mixed $body,
    ) {
    }
}
