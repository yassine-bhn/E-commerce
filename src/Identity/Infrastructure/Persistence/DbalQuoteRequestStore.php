<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Persistence;

use App\Identity\Application\Dto\QuoteRequestData;
use App\Identity\Application\Port\QuoteRequestStore;
use Doctrine\DBAL\Connection;

/**
 * DIP-003 — the quote-request intake write, moved verbatim from the handler.
 */
final class DbalQuoteRequestStore implements QuoteRequestStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function store(QuoteRequestData $data): void
    {
        $this->connection->executeStatement(
            'INSERT INTO identity_quote_requests (id, user_id, full_name, email, phone, city, category, subcategories, message, status)
             VALUES (:id, :user_id, :full_name, :email, :phone, :city, :category, :subcategories, :message, :status)',
            [
                'id' => $data->quoteId,
                'user_id' => $data->userId,
                'full_name' => $data->fullName,
                'email' => $data->email,
                'phone' => $data->phone,
                'city' => $data->city,
                'category' => $data->category,
                'subcategories' => json_encode($data->subcategories, JSON_THROW_ON_ERROR),
                'message' => $data->message,
                'status' => 'nouveau',
            ],
        );
    }
}
