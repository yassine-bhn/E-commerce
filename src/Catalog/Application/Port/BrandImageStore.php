<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/**
 * spec-run-19 R3/R5/R6 — the brand image writes. The reference itself is validated by the
 * domain value object; this port owns the authorization (the same policy as a rename, R10),
 * the row update and the one audit entry per success (R11), in the transaction the
 * command-bus middleware opened.
 */
interface BrandImageStore
{
    /**
     * Overwrites the brand's one image reference (a missing brand answers
     * BrandNotFoundException); never touches the approval status.
     */
    public function setImage(string $name, string $imagePath, string $actorId, bool $actorIsSuperAdmin): void;

    /**
     * Nulls the brand's image reference — idempotent: removing when no image is set succeeds
     * the same way (a missing brand answers BrandNotFoundException).
     */
    public function removeImage(string $name, string $actorId, bool $actorIsSuperAdmin): void;
}
