<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/**
 * solid Task 4.9 — the brand-table writes. The rename is the one write that changes a record's
 * identity: the row and every product carrying the name move together, and the submission
 * follows through the submission store (the one owner of that table).
 */
interface BrandStore
{
    /**
     * Authorizes (the access policy), refuses a case-insensitive clash, renames the brand row
     * and every product carrying the old name, moves the submission (or registers a legacy one)
     * with the reviewer save, and records the audit entry. Answers the affected product slugs.
     *
     * @return list<string>
     */
    public function rename(string $old, string $new, string $actorId, bool $actorIsSuperAdmin): array;

    /**
     * Refuses a brand that still carries products, deletes the row (a missing one answers
     * BrandNotFoundException) and removes its submission.
     */
    public function delete(string $name, string $actorId): void;

    /** Inserts the brand row; a taken slug answers BrandAlreadyExistsException. */
    public function create(string $name): void;
}
