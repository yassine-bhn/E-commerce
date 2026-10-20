<?php

declare(strict_types=1);

namespace App\Cart\Domain\Model;

final readonly class CartId
{
    public function __construct(private string $value)
    {
        if ('' === $value) {
            throw new \InvalidArgumentException('Cart id cannot be empty');
        }
    }

    public function toString(): string
    {
        return $this->value;
    }
}
