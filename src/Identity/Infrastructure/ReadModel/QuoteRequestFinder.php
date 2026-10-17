<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\ReadModel;

use App\Identity\Application\Query\ListQuoteRequestsForUser;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class QuoteRequestFinder
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<array<string, mixed>> */
    public function __invoke(ListQuoteRequestsForUser $query): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, category, subcategories, message, status, created_at
             FROM identity_quote_requests WHERE user_id = :user_id ORDER BY created_at DESC',
            ['user_id' => $query->userId],
        );

        return array_map(static function (array $row): array {
            /** @var list<string> $subs */
            $subs = json_decode((string) $row['subcategories'], true, 512, JSON_THROW_ON_ERROR);

            return [
                'id' => (string) $row['id'],
                'category' => (string) $row['category'],
                'subcategories' => $subs,
                'message' => (string) $row['message'],
                'status' => (string) $row['status'],
                'created_at' => (string) $row['created_at'],
            ];
        }, $rows);
    }
}
