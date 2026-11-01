<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Model\CatalogSubmission;
use App\Catalog\Domain\Model\CatalogSubmissionKind;

/**
 * WP2/ISP-001 — the owner-edit transitions: an approved record edited by its owner returns to
 * pending review, and a rename re-points the submission at its new key.
 */
interface CatalogSubmissionOwnerEditor
{
    public function ownerModified(CatalogSubmissionKind $kind, string $slug): CatalogSubmission;

    /**
     * Re-points an existing submission at a new key — the brand rename, the one workflow write
     * that changes the record's identity. The row keeps its whole state (status, owner, moments,
     * reason) and the view row is re-projected under the new key. Returns the moved submission,
     * or null when no row exists at the old key.
     */
    public function move(CatalogSubmissionKind $kind, string $fromSlug, string $toSlug): ?CatalogSubmission;
}
