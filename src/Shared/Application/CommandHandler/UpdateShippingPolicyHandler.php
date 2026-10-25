<?php

declare(strict_types=1);

namespace App\Shared\Application\CommandHandler;

use App\Shared\Application\Command\UpdateShippingPolicy;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * I-16 — the shipping policy is a singleton row (id = 1), so the upsert is the whole write path.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class UpdateShippingPolicyHandler
{
    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(UpdateShippingPolicy $command): void
    {
        $this->connection->executeStatement(
            'INSERT INTO storefront_shipping_policy (id, delivery_fee_minor, free_delivery_threshold_minor)
             VALUES (1, :fee, :threshold)
             ON CONFLICT (id) DO UPDATE SET
                 delivery_fee_minor = EXCLUDED.delivery_fee_minor,
                 free_delivery_threshold_minor = EXCLUDED.free_delivery_threshold_minor',
            [
                'fee' => $command->deliveryFeeMinor,
                'threshold' => $command->freeDeliveryThresholdMinor,
            ],
        );
    }
}
