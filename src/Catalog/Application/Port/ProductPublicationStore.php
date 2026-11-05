<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/**
 * DIP-003 — the one writer of the `published` flag (R28/R29/R30); it never touches
 * approval_status.
 */
interface ProductPublicationStore
{
    public function setPublished(string $slug, bool $published): void;
}
