<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

final readonly class ProductId
{
    public function __construct(private string $value)
    {
        if ('' === $value) {
            throw new \InvalidArgumentException('ProductId cannot be empty');
        }
    }

    public function toString(): string
    {
        return $this->value;
    }
}
