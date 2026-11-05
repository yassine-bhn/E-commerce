<?php

declare(strict_types=1);

namespace App\Shared\Application\CommandHandler;

use App\Shared\Application\Command\UpdatePlatformSettings;
use App\Shared\Application\Port\PlatformSettingsWriter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * I-16 — write side of the singleton row. The platform_setting migration fixes the row to id = 1,
 * so the upsert is the whole write path: no read-then-write, and replay is naturally idempotent.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class UpdatePlatformSettingsHandler
{
    public function __construct(private PlatformSettingsWriter $settings)
    {
    }

    public function __invoke(UpdatePlatformSettings $command): void
    {
        $this->settings->save(
            $command->contactPhone,
            $command->contactWhatsapp,
            $command->autoCancelMinutes,
            $command->paymentMethods,
        );
    }
}
