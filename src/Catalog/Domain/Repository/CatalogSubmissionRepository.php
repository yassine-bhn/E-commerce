<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\Model\CatalogSubmission;
use App\Catalog\Domain\Model\CatalogSubmissionKind;

/**
 * spec-run-15 WP2 (R2) — the domain-facing read of the approval write model.
 *
 * It exists so a caller that only needs the current state (a policy, a read model rebuild) can
 * depend on the aggregate instead of the store's write contract. The DBAL store is its only
 * adapter, because R2 allows one writer and one reader of `catalog_submission`.
 */
interface CatalogSubmissionRepository
{
    public function find(CatalogSubmissionKind $kind, string $slug): ?CatalogSubmission;
}
