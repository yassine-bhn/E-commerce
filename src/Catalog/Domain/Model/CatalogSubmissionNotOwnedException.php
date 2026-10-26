<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

/**
 * R35 — a caller outside the back office may only touch their own submission. A record with no
 * recorded submitter (a legacy row) is not theirs either.
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
