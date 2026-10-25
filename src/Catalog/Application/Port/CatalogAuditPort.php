<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/**
 * R39–R45 — every workflow command writes one append-only audit row for what it just did.
 *
 * The port lives in the application layer so a handler never names a storage detail, and the
 * write participates in the handler's transaction so a refused action leaves no trace.
 */
interface CatalogAuditPort
{
    /**
     * @param string      $action     one of the CATALOG_SUBMISSION_* verbs
     * @param string      $targetType `product` or `category`
     * @param string      $targetSlug the record the action applies to
     * @param string      $actorId    the administrator who performed it
     * @param string|null $reason     the rejection reason, when the action carries one
     */
    public function record(
        string $action,
        string $targetType,
        string $targetSlug,
        string $actorId,
        ?string $reason = null,
    ): void;
}
