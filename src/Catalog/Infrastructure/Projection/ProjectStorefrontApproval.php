<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Projection;

use App\Catalog\Domain\Model\CatalogSubmission;
use Doctrine\DBAL\Connection;

/**
 * spec-run-15 WP2 (R2/R3) — the only writer of the six approval columns in the two view tables.
 *
 * It runs inside the store's transaction, so the admin read model shows the new state as soon as
 * the command returns. It only updates by the kind's key column (slug, or the brand name) and
 * never inserts: a missing view row is a content
 * problem the owner fixes, and inserting one here would fabricate a product or category. Publication
 * (`published`, `hidden`) is deliberately out of scope — approval is not publishing.
 */
final class ProjectStorefrontApproval
{
    private const string COLUMNS = 'approval_status = :status,
            submitted_by = :submitted_by,
            submitted_at = :submitted_at,
            reviewed_by = :reviewed_by,
            reviewed_at = :reviewed_at,
            rejection_reason = :rejection_reason';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function project(CatalogSubmission $submission): void
    {
        $this->connection->executeStatement(
            sprintf(
                'UPDATE %s SET %s WHERE %s = :slug',
                $submission->kind()->table(),
                self::COLUMNS,
                $submission->kind()->keyColumn(),
            ),
            [
                'status' => $submission->status()->value,
                'submitted_by' => $submission->submittedBy(),
                'submitted_at' => $submission->submittedAt()?->format('Y-m-d H:i:s.uP'),
                'reviewed_by' => $submission->reviewedBy(),
                'reviewed_at' => $submission->reviewedAt()?->format('Y-m-d H:i:s.uP'),
                'rejection_reason' => $submission->rejectionReason(),
                'slug' => $submission->slug(),
            ],
        );
    }
}
