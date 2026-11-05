<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence;

use App\Shared\Application\Port\ShippingPolicyWriter;
use Doctrine\DBAL\Connection;

/**
 * DIP-003 — the shipping policy write, moved verbatim from the handler.
 */
final class DbalShippingPolicyWriter implements ShippingPolicyWriter
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(int $feeMinor, int $thresholdMinor): void
    {
        $this->connection->executeStatement(
            'INSERT INTO storefront_shipping_policy (id, delivery_fee_minor, free_delivery_threshold_minor)
             VALUES (1, :fee, :threshold)
             ON CONFLICT (id) DO UPDATE SET
                 delivery_fee_minor = EXCLUDED.delivery_fee_minor,
                 free_delivery_threshold_minor = EXCLUDED.free_delivery_threshold_minor',
            [
                'fee' => $feeMinor,
                'threshold' => $thresholdMinor,
            ],
        );
    }
}
