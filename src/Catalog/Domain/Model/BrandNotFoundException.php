<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

final class BrandNotFoundException extends \DomainException
{
    public static function forName(string $name): self
    {
        return new self(sprintf('Brand "%s" does not exist.', $name));
    }
}
