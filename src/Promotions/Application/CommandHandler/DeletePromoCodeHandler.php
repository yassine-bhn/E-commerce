<?php

declare(strict_types=1);

namespace App\Promotions\Application\CommandHandler;

use App\Promotions\Application\Command\DeletePromoCode;
use App\Promotions\Application\Port\PromoCodeStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * solid Task 4.10 — the delete; a missing code answers PromoCodeNotFoundException (404).
 */
#[AsMessageHandler(bus: 'command.bus')]
final class DeletePromoCodeHandler
{
    public function __construct(private PromoCodeStore $store)
    {
    }

    public function __invoke(DeletePromoCode $command): void
    {
        $this->store->delete($command->code);
    }
}
