<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\SubmitQuoteRequest;
use App\Identity\Application\Dto\QuoteRequestData;
use App\Identity\Application\Port\QuoteRequestStore;
use App\Shared\Application\Port\StorefrontCategoryPort;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class SubmitQuoteRequestHandler
{
    public function __construct(
        private QuoteRequestStore $requests,
        private StorefrontCategoryPort $categories,
    ) {
    }

    public function __invoke(SubmitQuoteRequest $command): void
    {
        if (!$this->categories->isQuoteEligible($command->category)) {
            throw new \InvalidArgumentException('Invalid quote category');
        }

        $this->requests->store(new QuoteRequestData(
            quoteId: $command->quoteId,
            userId: $command->userId,
            fullName: $command->fullName,
            email: $command->email,
            phone: $command->phone,
            city: $command->city,
            category: $command->category,
            subcategories: $command->subcategories,
            message: $command->message,
        ));
    }
}
