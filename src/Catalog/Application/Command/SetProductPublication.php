<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

/**
 * R28/R29 — publication is its own decision, never a consequence of approval.
 */
final readonly class SetProductPublication
{
    public function __construct(
        public string $slug,
        public bool $published,
        public string $actorId,
    ) {
    }
}
