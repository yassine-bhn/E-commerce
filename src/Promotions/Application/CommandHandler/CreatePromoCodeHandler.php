<?php

declare(strict_types=1);

namespace App\Promotions\Application\CommandHandler;

use App\Promotions\Application\Command\CreatePromoCode;
use App\Promotions\Application\Port\PromoCodeReader;
use App\Promotions\Application\Port\PromoCodeStore;
use App\Promotions\Domain\Model\PromoCodeAlreadyExistsException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * solid Task 4.10 — a duplicate code is refused before anything is written (409).
 */
#[AsMessageHandler(bus: 'command.bus')]
final class CreatePromoCodeHandler
{
    public function __construct(
        private PromoCodeReader $codes,
        private PromoCodeStore $store,
    ) {
    }

    public function __invoke(CreatePromoCode $command): void
    {
        if ($this->codes->exists($command->code)) {
            throw PromoCodeAlreadyExistsException::forCode($command->code);
        }

        $this->store->create($command->code, $command->discountBasisPoints, $command->active);
    }
}
