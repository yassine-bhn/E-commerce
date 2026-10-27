<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service;

use App\Catalog\Domain\Model\ApprovalStatus;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\CatalogSubmissionNotFoundException;
use App\Catalog\Domain\Model\CatalogSubmissionNotOwnedException;
use Doctrine\DBAL\Connection;

/**
 * spec-run-8 R7/R8/R25/R35 — the one place that decides who may modify a catalog record.
 *
 * A Super Admin may write anything. A regular Admin may only write their own submission, and
 * only while it is still `PENDING_APPROVAL` or `REJECTED`: an approved record is out of their
 * reach until a Super Admin reopens it. The status the guard returns is the status the caller
 * must write back, so the reapproval rule (R25) is applied by the same read that authorised it.
 */
final class SubmissionAccessPolicy
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function authorize(
        CatalogSubmissionKind $kind,
        string $slug,
        ?string $actorId,
        bool $actorIsSuperAdmin,
    ): ApprovalStatus {
        $row = $this->connection->fetchAssociative(
            sprintf('SELECT approval_status, submitted_by FROM %s WHERE slug = :slug', $kind->table()),
            ['slug' => $slug],
        );
        if (false === $row) {
            throw CatalogSubmissionNotFoundException::for($kind, $slug);
        }

        $status = ApprovalStatus::fromStorage((string) $row['approval_status']);
        if ($actorIsSuperAdmin || null === $actorId) {
            return $status;
        }

        $owner = is_string($row['submitted_by'] ?? null) ? $row['submitted_by'] : null;
        if ($owner !== $actorId || !$status->canBeEditedByOwner()) {
            throw CatalogSubmissionNotOwnedException::for($kind, $slug);
        }

        return $status;
    }
}
