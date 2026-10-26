<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Domain\Model\ApprovalStatus;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Shared\Application\Pagination\PageRequest;

/**
 * spec-run-8 R5/R15/R16 — one bounded page of submissions for the caller.
 *
 * Back-office staff see every row of the requested kind; anybody outside it only ever sees the
 * rows they submitted. Optionally narrowed to one approval state (queue is `PENDING_APPROVAL`).
 */
final readonly class ListCatalogSubmissions
{
    public const int DEFAULT_SIZE = 50;
    public const int MAX_SIZE = 200;

    public function __construct(
        public CatalogSubmissionKind $kind,
        public string $requesterId,
        public bool $isAdmin,
        public PageRequest $page = new PageRequest(1, self::DEFAULT_SIZE, self::MAX_SIZE),
        public ?ApprovalStatus $approvalStatus = null,
        public ?string $q = null,
    ) {
    }
}
