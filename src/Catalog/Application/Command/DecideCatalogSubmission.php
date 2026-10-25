<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Domain\Model\CatalogDecision;
use App\Catalog\Domain\Model\CatalogSubmissionKind;

/**
 * R22/R23 — the Super Admin ends a review. Approve and reject are the same task with two
 * outcomes, so they share one command; the decision, never the payload, says which one happens.
 */
final readonly class DecideCatalogSubmission
{
    public function __construct(
        public CatalogSubmissionKind $kind,
        public string $slug,
        public CatalogDecision $decision,
        public string $reviewerId,
        public ?string $reason = null,
    ) {
    }
}
