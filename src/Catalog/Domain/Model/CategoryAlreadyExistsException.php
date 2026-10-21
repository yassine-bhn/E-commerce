<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

final class CategoryAlreadyExistsException extends \DomainException
{
    public static function forSlug(string $slug): self
    {
        return new self(sprintf('A category with the slug "%s" already exists.', $slug));
    }
}
