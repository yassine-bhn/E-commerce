<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

use App\Catalog\Application\Query\GetCatalogSubmission;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * spec-run-8 R5/R6/R15/R16 — one submission, read through the same predicate as the list.
 *
 * The finder owns the SQL, so "mine only" cannot be weaker on the detail route than it is on the
 * queue (R38).
 */
#[AsMessageHandler(bus: 'query.bus')]
final class CatalogSubmissionDetailHandler
{
    public function __construct(private readonly CatalogSubmissionFinder $finder)
    {
    }

    public function __invoke(GetCatalogSubmission $query): ?CatalogSubmissionView
    {
        return $this->finder->one($query->kind, $query->slug, $query->requesterId, $query->isAdmin);
    }
}
