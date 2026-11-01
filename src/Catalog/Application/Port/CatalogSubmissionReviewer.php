<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Model\CatalogSubmission;
use App\Catalog\Domain\Model\CatalogSubmissionKind;

/**
 * WP2/ISP-001 — the reviewer-save side of an auto-approving creation: the row is written by the
 * same transaction that creates or updates the content, and the store records the transition.
 */
interface CatalogSubmissionReviewer
{
    public function reviewerSave(
        CatalogSubmissionKind $kind,
        string $slug,
        string $actorId,
    ): CatalogSubmission;
}
