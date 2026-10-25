<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\ReadModel;

use App\Shared\Application\Query\GetStorefrontReference;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class GetStorefrontReferenceHandler
{
    public function __construct(private StorefrontReferenceRepository $reference)
    {
    }

    /** @return array<string, mixed> */
    public function __invoke(GetStorefrontReference $query): array
    {
        $policy = $this->reference->deliveryPolicy();

        return [
            'categories' => $this->reference->categories(),
            'brands' => $this->reference->brands(),
            'governorates' => $this->reference->governorates(),
            'promoCodes' => $this->reference->activePromoCodes(),
            'shipping' => [
                'deliveryFee' => $policy->deliveryFeeMinor,
                'freeDeliveryThreshold' => $policy->freeDeliveryThresholdMinor,
            ],
            'contact' => $this->reference->contactChannels(),
        ];
    }
}
