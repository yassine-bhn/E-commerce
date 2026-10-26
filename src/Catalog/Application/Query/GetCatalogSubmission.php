<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Domain\Model\CatalogSubmissionKind;

/**
 * spec-run-8 R5/R6/R15/R16 — the approval view of one record, open to the whole back office.
 *
 * A caller outside the back office asking for somebody else's record gets the same answer as for
 * a missing one: `null`. Ownership is decided by the SQL predicate, never by the caller (QRY-004).
 */
final readonly class GetCatalogSubmission
{
    public function __construct(
        public CatalogSubmissionKind $kind,
        public string $slug,
        public string $requesterId,
        public bool $isAdmin,
    ) {
    }
}
