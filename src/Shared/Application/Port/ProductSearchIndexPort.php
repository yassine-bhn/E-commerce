<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

/**
 * DIP-003 — the search index mirror, so Application code never names the indexer client.
 */
interface ProductSearchIndexPort
{
    /** Mirrors the product's current public state into the index. */
    public function sync(string $slug): void;

    /** Drops the product from the index (deletion). */
    public function forget(string $slug): void;
}
