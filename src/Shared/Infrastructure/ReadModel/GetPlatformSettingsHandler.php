<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\ReadModel;

use App\Shared\Application\Query\GetPlatformSettings;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class GetPlatformSettingsHandler
{
    public function __construct(private PlatformSettingsRepository $settings)
    {
    }

    /** @return array{contactPhone: string, contactWhatsapp: string, autoCancelMinutes: int} */
    public function __invoke(GetPlatformSettings $query): array
    {
        return $this->settings->get();
    }
}
