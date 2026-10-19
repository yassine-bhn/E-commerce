<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\ReadModel;

use App\Shared\Application\Query\GetStorefrontFaq;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class GetStorefrontFaqHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<array{id: string, category: string, question: string, answer: string}> */
    public function __invoke(GetStorefrontFaq $query): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, category, question, answer
             FROM storefront_faq_entry
             ORDER BY sort_order ASC, question ASC',
        );

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'category' => (string) $row['category'],
            'question' => (string) $row['question'],
            'answer' => (string) $row['answer'],
        ], $rows);
    }
}
