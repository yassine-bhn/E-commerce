<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

/**
 * DIP-003 — the shipping policy singleton upsert (I-16).
 */
interface ShippingPolicyWriter
{
    public function save(int $feeMinor, int $thresholdMinor): void;
}
