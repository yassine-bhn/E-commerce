<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

final class BrandNotEmptyException extends \DomainException
{
    public static function forBrand(string $name, int $productsCount): self
    {
        return new self(sprintf(
            'Cannot delete brand "%s": %d product(s) still use it. Reassign or delete them first.',
            $name,
            $productsCount,
        ));
    }
}
