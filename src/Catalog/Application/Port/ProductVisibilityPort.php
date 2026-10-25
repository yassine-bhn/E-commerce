<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/**
 * spec-run-12 R17/R18 — the question a write has to ask the read side: may the storefront see this
 * product at all?
 *
 * The predicate itself belongs to the read model (one SQL definition, composed by every public read),
 * so the command side asks through this port instead of importing Infrastructure and restating the
 * rule.
 */
interface ProductVisibilityPort
{
    public function isPubliclyVisible(string $slug): bool;
}
