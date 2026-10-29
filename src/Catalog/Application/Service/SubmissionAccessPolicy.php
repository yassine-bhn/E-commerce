<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service;

use App\Catalog\Domain\Model\ApprovalStatus;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\CatalogSubmissionNotFoundException;
use App\Catalog\Domain\Model\CatalogSubmissionNotOwnedException;
use App\Catalog\Domain\Repository\CatalogSubmissionRepository;

/**
 * spec-run-8 R7/R8/R25/R35, spec-run-15 R2 — the one place that decides who may modify a catalog
 * record.
 *
 * A Super Admin may write anything. A regular Admin may only write their own submission, and only
 * while it is still `PENDING_APPROVAL` or `REJECTED`: an approved record is out of their reach until
 * it is reopened. The state is read from the approval write model through
 * {@see CatalogSubmissionRepository}, never from either view table, which is why a stale projection
 * cannot transfer ownership or authorise an edit.
 */
final class SubmissionAccessPolicy
{
    public function __construct(private readonly CatalogSubmissionRepository $submissions)
    {
    }

    public function authorize(
        CatalogSubmissionKind $kind,
        string $slug,
        ?string $actorId,
        bool $actorIsSuperAdmin,
    ): ApprovalStatus {
        $submission = $this->submissions->find($kind, $slug);
        if (null === $submission) {
            throw CatalogSubmissionNotFoundException::for($kind, $slug);
        }

        $status = $submission->status();
        if ($actorIsSuperAdmin || null === $actorId) {
            return $status;
        }

        if ($submission->submittedBy() !== $actorId || !$status->canBeEditedByOwner()) {
            throw CatalogSubmissionNotOwnedException::for($kind, $slug);
        }

        return $status;
    }
}
