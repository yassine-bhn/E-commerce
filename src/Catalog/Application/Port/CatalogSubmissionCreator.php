<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Model\CatalogSubmission;
use App\Catalog\Domain\Model\CatalogSubmissionKind;

/**
 * WP2/ISP-001 — the creation side of the approval store: a new submission (one transaction:
 * submission row, outbox event, audit entry and the six projected view columns) and the legacy
 * backfill seed. No other class inserts `catalog_submission`.
 */
interface CatalogSubmissionCreator
{
    public function create(CatalogSubmissionKind $kind, string $slug, string $actorId): CatalogSubmission;

    /**
     * Seeds a state that already exists outside the write model (legacy backfill). Returns whether
     * the row was inserted; an already-present row is left untouched. No outbox event, no audit.
     */
    public function import(CatalogSubmission $submission): bool;

    /**
     * solid Task 4.11 — the auto-approved registration (a brand carried by a product, or a
     * Marques create): the submission is written and approved in one transition.
     */
    public function createApproved(CatalogSubmissionKind $kind, string $slug, string $actorId): void;
}
