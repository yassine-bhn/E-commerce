<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\SubmitQuoteRequest;
use App\Shared\Application\Port\StorefrontCategoryPort;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class SubmitQuoteRequestHandler
{
    public function __construct(
        private Connection $connection,
        private StorefrontCategoryPort $categories,
    ) {
    }

    public function __invoke(SubmitQuoteRequest $command): void
    {
        if (!$this->categories->isQuoteEligible($command->category)) {
            throw new \InvalidArgumentException('Invalid quote category');
        }

        $this->connection->executeStatement(
            'INSERT INTO identity_quote_requests (id, user_id, full_name, email, phone, city, category, subcategories, message, status)
             VALUES (:id, :user_id, :full_name, :email, :phone, :city, :category, :subcategories, :message, :status)',
            [
                'id' => $command->quoteId,
                'user_id' => $command->userId,
                'full_name' => $command->fullName,
                'email' => $command->email,
                'phone' => $command->phone,
                'city' => $command->city,
                'category' => $command->category,
                'subcategories' => json_encode($command->subcategories, JSON_THROW_ON_ERROR),
                'message' => $command->message,
                'status' => 'nouveau',
            ],
        );
    }
}
