<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\ReadModel;

use App\Shared\Application\Query\GetPaymentMethods;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * solid Task 4.1 — the operator-enabled payment methods (O-19), readable through the query bus
 * so the checkout controller never names the settings repository.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class GetPaymentMethodsHandler
{
    public function __construct(private PlatformSettingsRepository $settings)
    {
    }

    /** @return list<string> */
    public function __invoke(GetPaymentMethods $query): array
    {
        return $this->settings->paymentMethods();
    }
}
