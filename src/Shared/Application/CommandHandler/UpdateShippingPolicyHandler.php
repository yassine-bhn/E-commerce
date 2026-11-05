<?php

declare(strict_types=1);

namespace App\Shared\Application\CommandHandler;

use App\Shared\Application\Command\UpdateShippingPolicy;
use App\Shared\Application\Port\ShippingPolicyWriter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * I-16 — the shipping policy is a singleton row (id = 1), so the upsert is the whole write path.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class UpdateShippingPolicyHandler
{
    public function __construct(private ShippingPolicyWriter $policies)
    {
    }

    public function __invoke(UpdateShippingPolicy $command): void
    {
        $this->policies->save($command->deliveryFeeMinor, $command->freeDeliveryThresholdMinor);
    }
}
