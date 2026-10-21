<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

final class CategoryLabelInvalidException extends \DomainException
{
    public static function empty(): self
    {
        return new self('A category label is required.');
    }

    public static function tooLong(int $length): self
    {
        return new self(sprintf('A category label must be %d characters or fewer, %d given.', Category::MAX_LABEL_LENGTH, $length));
    }
}
