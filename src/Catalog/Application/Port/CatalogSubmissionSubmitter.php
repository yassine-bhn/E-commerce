<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Model\CatalogSubmission;
use App\Catalog\Domain\Model\CatalogSubmissionKind;

/**
 * WP2/ISP-001 — the owner's submit transition: PENDING review states only, one transaction.
 */
interface CatalogSubmissionSubmitter
{
    public function submit(
        CatalogSubmissionKind $kind,
        string $slug,
        string $actorId,
        bool $actorIsSuperAdmin,
    ): CatalogSubmission;
}
