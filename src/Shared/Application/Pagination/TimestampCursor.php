<?php

declare(strict_types=1);

namespace App\Shared\Application\Pagination;

use Symfony\Component\Uid\Uuid;

/**
 * Cursor payload validation for keysets ordered on a `timestamptz` column and a UUID id
 * (`ORDER BY k DESC, i DESC`, predicate `(k, i) < (:k, :i)`).
 *
 * A cursor is opaque; anything that does not decode into a Postgres timestamp text plus a
 * valid UUID is rejected as malformed so the HTTP layer can answer `400 Invalid cursor`
 * instead of letting the database fail on a cast.
 */
final class TimestampCursor
{
    /** Postgres `timestamptz` text form, e.g. `2026-10-20 11:00:00+00`. */
    private const string PATTERN = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:[+-]\d{2}(?::?\d{2})?)$/';

    /**
     * @return array{key: string, id: string}|null `null` when the request carried no cursor
     *
     * @throws InvalidCursorException when the payload is malformed
     */
    public static function decode(?string $raw): ?array
    {
        if (null === $raw) {
            return null;
        }

        $cursor = Cursor::decode($raw);

        if (!Uuid::isValid($cursor['id']) || 1 !== preg_match(self::PATTERN, $cursor['key'])) {
            throw InvalidCursorException::create();
        }

        return $cursor;
    }
}
