<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

final class BrandAlreadyExistsException extends \DomainException
{
    public static function forName(string $name): self
    {
        return new self(sprintf('A brand with the name "%s" already exists.', $name));
    }
}
