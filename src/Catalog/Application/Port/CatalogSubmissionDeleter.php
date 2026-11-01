<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Model\CatalogSubmissionKind;

/**
 * WP2/ISP-001 — deleting the record removes its submission with the same transaction rules.
 */
interface CatalogSubmissionDeleter
{
    public function delete(CatalogSubmissionKind $kind, string $slug): void;
}
