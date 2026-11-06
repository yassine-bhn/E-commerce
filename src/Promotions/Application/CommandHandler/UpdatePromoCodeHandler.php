<?php

declare(strict_types=1);

namespace App\Promotions\Application\CommandHandler;

use App\Promotions\Application\Command\UpdatePromoCode;
use App\Promotions\Application\Port\PromoCodeReader;
use App\Promotions\Application\Port\PromoCodeStore;
use App\Promotions\Domain\Model\PromoCodeNotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * solid Task 4.10 — the merge update: null fields keep their stored value, so the current row
 * is read through the reader and the merged result written in one statement.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class UpdatePromoCodeHandler
{
    public function __construct(
        private PromoCodeReader $codes,
        private PromoCodeStore $store,
    ) {
    }

    public function __invoke(UpdatePromoCode $command): void
    {
        $current = $this->codes->find($command->code);
        if (null === $current) {
            throw PromoCodeNotFoundException::forCode($command->code);
        }

        $this->store->update(
            $command->code,
            $command->discountBasisPoints ?? $current['discount_basis_points'],
            $command->active ?? $current['active'],
        );
    }
}
