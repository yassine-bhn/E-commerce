<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

/**
 * ERR-002 — the slide the dashboard named is not part of the banner. Mapped to a 404 by the
 * Infrastructure controller; the domain itself only speaks in terms of the set.
 */
final class BannerImageNotFoundException extends \DomainException
{
    public static function withId(string $imageId): self
    {
        return new self(sprintf('Banner image "%s" does not exist.', $imageId));
    }
}
