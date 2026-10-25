<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Domain\Model\CatalogSubmissionKind;

/**
 * R3/R4/R9/R24 — the owning Admin asks the Super Admin to review a submission.
 *
 * A record is already `PENDING_APPROVAL` from the moment it is created (R10), so submitting a
 * pending record is an explicit but idempotent act: it records the intent and nothing else. A
 * rejected record is the interesting case — submission is the only way back into the queue, and
 * it clears the previous outcome. An approved record cannot be submitted: only a modification
 * reopens it (R25).
 */
final readonly class SubmitCatalogSubmission
{
    public function __construct(
        public CatalogSubmissionKind $kind,
        public string $slug,
        public string $actorId,
        public bool $actorIsSuperAdmin = false,
    ) {
    }
}
