<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Domain\Model\ApprovalStatus;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Shared\Application\Pagination\PageRequest;

/**
 * spec-run-8 R5/R15/R16 — one bounded page of submissions for the caller.
 *
 * A regular Admin only ever sees their own rows; the Super Admin sees every row of the requested
 * kind, optionally narrowed to one approval state (the review queue is `PENDING_APPROVAL`).
 */
final readonly class ListCatalogSubmissions
{
    public const int DEFAULT_SIZE = 50;
    public const int MAX_SIZE = 200;

    public function __construct(
        public CatalogSubmissionKind $kind,
        public string $requesterId,
        public bool $isSuperAdmin,
        public PageRequest $page = new PageRequest(1, self::DEFAULT_SIZE, self::MAX_SIZE),
        public ?ApprovalStatus $approvalStatus = null,
        public ?string $q = null,
    ) {
    }
}
