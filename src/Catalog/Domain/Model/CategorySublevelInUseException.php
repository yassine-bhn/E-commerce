<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

final class CategorySublevelInUseException extends \DomainException
{
    /**
     * @param list<string> $labels
     */
    public static function forLabels(string $slug, array $labels): self
    {
        return new self(sprintf(
            'Cannot remove the subcategory "%s" of category "%s": %d product(s) still use it.',
            implode('", "', $labels),
            $slug,
            count($labels),
        ));
    }
}
