<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence;

use App\Shared\Application\Port\PlatformSettingsWriter;
use Doctrine\DBAL\Connection;

/**
 * DIP-003 — the platform settings write, moved verbatim from the handler.
 */
final class DbalPlatformSettingsWriter implements PlatformSettingsWriter
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(string $phone, string $whatsapp, int $autoCancelMinutes, array $paymentMethods): void
    {
        $this->connection->executeStatement(
            'INSERT INTO platform_setting (id, contact_phone, contact_whatsapp, auto_cancel_minutes, payment_methods, updated_at)
             VALUES (1, :phone, :whatsapp, :minutes, :methods, NOW())
             ON CONFLICT (id) DO UPDATE SET
                 contact_phone = EXCLUDED.contact_phone,
                 contact_whatsapp = EXCLUDED.contact_whatsapp,
                 auto_cancel_minutes = EXCLUDED.auto_cancel_minutes,
                 payment_methods = EXCLUDED.payment_methods,
                 updated_at = NOW()',
            [
                'phone' => $phone,
                'whatsapp' => $whatsapp,
                'minutes' => $autoCancelMinutes,
                'methods' => json_encode($paymentMethods, JSON_THROW_ON_ERROR),
            ],
        );
    }
}
