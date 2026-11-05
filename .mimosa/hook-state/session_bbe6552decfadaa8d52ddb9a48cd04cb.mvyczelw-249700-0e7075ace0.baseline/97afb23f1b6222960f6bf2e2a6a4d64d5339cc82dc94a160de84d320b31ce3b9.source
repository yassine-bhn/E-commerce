<?php

declare(strict_types=1);

namespace App\Shared\Application\CommandHandler;

use App\Shared\Application\Command\UpdatePlatformSettings;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * I-16 — write side of the singleton row. The platform_setting migration fixes the row to id = 1,
 * so the upsert is the whole write path: no read-then-write, and replay is naturally idempotent.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class UpdatePlatformSettingsHandler
{
    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(UpdatePlatformSettings $command): void
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
                'phone' => $command->contactPhone,
                'whatsapp' => $command->contactWhatsapp,
                'minutes' => $command->autoCancelMinutes,
                'methods' => json_encode($command->paymentMethods, JSON_THROW_ON_ERROR),
            ],
        );
    }
}
