<?php

declare(strict_types=1);

namespace App\Identity\Application;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Which conflict a unique violation is (spec-run-12 R7, R10).
 *
 * The unique index on `lower(email)` is the durable answer to "is this address taken?" — and the
 * only one available once the violation itself has aborted the handler's transaction, because the
 * command bus runs every handler inside one (`doctrine_transaction`). A query cannot be asked in an
 * aborted transaction, so the index PostgreSQL named in the error is what separates an e-mail
 * conflict from any other unique violation.
 */
final class EmailUniqueness
{
    /** The index created by Version20261024000000. */
    public const string INDEX = 'uniq_customer_profile_email_lower';

    public static function isEmailCollision(UniqueConstraintViolationException $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), self::INDEX);
    }
}
