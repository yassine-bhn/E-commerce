<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

/**
 * No node of the category's `subcategories` tree answers the label path a write addressed.
 * Thrown by the domain, mapped to a 404 by the Infrastructure controller (ERR-002).
 */
final class SubcategoryNotFoundException extends \DomainException
{
    /**
     * @param list<string> $labelPath
     */
    public static function forPath(array $labelPath): self
    {
        return new self(sprintf(
            'No subcategory answers the label path "%s".',
            '' === implode('', $labelPath) ? '(empty)' : implode(' > ', $labelPath),
        ));
    }
}
