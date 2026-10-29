<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Model\CatalogDecision;
use App\Catalog\Domain\Model\CatalogSubmission;
use App\Catalog\Domain\Model\CatalogSubmissionKind;

/**
 * spec-run-15 WP2 (R2/R3) — the single application entry point for an approval transition.
 *
 * WP3 calls this port from every creation, submit, decision, reviewer-save and owner-edit path.
 * Each method owns one database transaction: the submission row, exactly one outbox event, the
 * audit entry and the six projected view columns commit together or not at all. No other class
 * inserts, updates or deletes `catalog_submission`.
 */
interface CatalogSubmissionStore
{
    public function create(CatalogSubmissionKind $kind, string $slug, string $actorId): CatalogSubmission;

    /**
     * Seeds a state that already exists outside the write model (legacy backfill). Returns whether
     * the row was inserted; an already-present row is left untouched. No outbox event, no audit.
     */
    public function import(CatalogSubmission $submission): bool;

    public function find(CatalogSubmissionKind $kind, string $slug): ?CatalogSubmission;

    public function submit(
        CatalogSubmissionKind $kind,
        string $slug,
        string $actorId,
        bool $actorIsSuperAdmin,
    ): CatalogSubmission;

    public function decide(
        CatalogSubmissionKind $kind,
        string $slug,
        CatalogDecision $decision,
        string $reviewerId,
        ?string $reason = null,
    ): CatalogSubmission;

    public function reviewerSave(
        CatalogSubmissionKind $kind,
        string $slug,
        string $actorId,
    ): CatalogSubmission;

    public function ownerModified(CatalogSubmissionKind $kind, string $slug): CatalogSubmission;

    public function delete(CatalogSubmissionKind $kind, string $slug): void;
}
