<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\ReadModel;

use App\Identity\Application\Query\ListQuoteRequestsForUser;
use App\Shared\Application\Pagination\Cursor;
use App\Shared\Application\Pagination\CursorPage;
use App\Shared\Application\Pagination\TimestampCursor;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * R8 — the authenticated user's quote requests as a keyset page on `(created_at, id)`.
 *
 * The `user_id` scope is part of the base predicate, so no filter can widen the read to
 * another customer's rows (QRY-004). One page costs a single statement.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class QuoteRequestFinder
{
    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(ListQuoteRequestsForUser $query): CursorPage
    {
        $limit = $query->cursor->effectiveLimit();

        $conditions = ['user_id = :user_id'];
        $params = ['user_id' => $query->userId];

        if (null !== $query->status) {
            $conditions[] = 'status = :status';
            $params['status'] = $query->status;
        }

        if (null !== $query->dateFrom) {
            $conditions[] = 'created_at >= CAST(:date_from AS date)';
            $params['date_from'] = $query->dateFrom->format('Y-m-d');
        }

        if (null !== $query->dateTo) {
            $conditions[] = "created_at < CAST(:date_to AS date) + INTERVAL '1 day'";
            $params['date_to'] = $query->dateTo->format('Y-m-d');
        }

        if (null !== $query->q) {
            $conditions[] = "(category ILIKE :q ESCAPE '\\' OR city ILIKE :q ESCAPE '\\' OR message ILIKE :q ESCAPE '\\')";
            $params['q'] = '%'.self::escapeLike($query->q).'%';
        }

        $cursor = TimestampCursor::decode($query->cursor->rawCursor());
        if (null !== $cursor) {
            $conditions[] = '(created_at, id) < (CAST(:cursor_key AS timestamptz), CAST(:cursor_id AS uuid))';
            $params['cursor_key'] = $cursor['key'];
            $params['cursor_id'] = $cursor['id'];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, category, subcategories, message, status, created_at
             FROM identity_quote_requests
             WHERE '.implode(' AND ', $conditions).'
             ORDER BY created_at DESC, id DESC
             LIMIT '.($limit + 1),
            $params,
        );

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        $nextCursor = null;
        if ($hasMore && [] !== $rows) {
            $last = $rows[array_key_last($rows)];
            $nextCursor = Cursor::encode((string) $last['created_at'], (string) $last['id']);
        }

        return new CursorPage(
            array_map(static function (array $row): array {
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
            }, $rows),
            $limit,
            $hasMore,
            $nextCursor,
        );
    }

    private static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
