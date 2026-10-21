<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

/**
 * R20/R21 — read the site banner's ordered slide set. It carries the requester's roles, never a
 * client-supplied flag, so the finder itself decides what the caller may see (QRY-004 / SEC-002).
 */
final readonly class GetBannerImages
{
    /**
     * @param list<string> $requesterRoles roles of the authenticated requester, empty when anonymous
     */
    public function __construct(
        public array $requesterRoles = [],
    ) {
    }
}
