<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Support;

use App\Catalog\Application\Port\CatalogAuditPort;

/**
 * R38 — the audit write is the second half of the decision transaction. This port fails it on
 * purpose so a test can observe what the handler leaves behind when the trail cannot be written.
 */
final class FailingCatalogAuditPort implements CatalogAuditPort
{
    public const string MESSAGE = 'catalog audit trail unavailable';

    public function record(
        string $action,
        string $targetType,
        string $targetSlug,
        string $actorId,
        ?string $reason = null,
    ): void {
        throw new \RuntimeException(self::MESSAGE);
    }
}
