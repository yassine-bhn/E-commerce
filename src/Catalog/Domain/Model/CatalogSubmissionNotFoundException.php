<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

/** The submission a workflow command names does not exist (ERR-002: the edge maps it to 404). */
final class CatalogSubmissionNotFoundException extends \DomainException
{
    public static function for(CatalogSubmissionKind $kind, string $slug): self
    {
        return new self(sprintf('The %s "%s" was not found.', strtolower($kind->label()), $slug));
    }
}
