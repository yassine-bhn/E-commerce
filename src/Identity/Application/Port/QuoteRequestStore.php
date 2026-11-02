<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

use App\Identity\Application\Dto\QuoteRequestData;

/**
 * DIP-003 — the quote request intake write.
 */
interface QuoteRequestStore
{
    public function store(QuoteRequestData $data): void;
}
