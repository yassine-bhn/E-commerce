<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

/**
 * DIP-003 — the platform settings singleton upsert (I-16: the row is fixed to id = 1, so the
 * upsert is the whole write path and replay is naturally idempotent).
 */
interface PlatformSettingsWriter
{
    /**
     * @param list<string> $paymentMethods
     */
    public function save(string $phone, string $whatsapp, int $autoCancelMinutes, array $paymentMethods): void;
}
