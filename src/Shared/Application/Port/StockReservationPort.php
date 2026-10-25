<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

interface StockReservationPort
{
    /** @param list<array{slug: string, quantity: int}> $lines */
    public function reserve(string $orderId, array $lines): void;

    public function release(string $orderId): void;
}
