<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Model\CatalogDecision;
use App\Catalog\Domain\Model\CatalogSubmission;
use App\Catalog\Domain\Model\CatalogSubmissionKind;

/**
 * WP2/ISP-001 — the reviewer's approve/reject transition, one transaction.
 */
interface CatalogSubmissionDecider
{
    public function decide(
        CatalogSubmissionKind $kind,
        string $slug,
        CatalogDecision $decision,
        string $reviewerId,
        ?string $reason = null,
    ): CatalogSubmission;
}
