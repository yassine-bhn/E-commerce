<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

/**
 * R35 — a regular Admin may only touch their own submission. A record with no recorded submitter
 * (a legacy or Super-Admin-created row) is not theirs either.
 */
final class CatalogSubmissionNotOwnedException extends \DomainException
{
    public static function for(CatalogSubmissionKind $kind, string $slug): self
    {
        return new self(sprintf(
            'The %s "%s" was submitted by another administrator.',
            strtolower($kind->label()),
            $slug,
        ));
    }
}
