<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Model;

final readonly class OrderId
{
    public function __construct(private string $value)
    {
        if ('' === $value) {
            throw new \InvalidArgumentException('OrderId cannot be empty');
        }
    }

    public function toString(): string
    {
        return $this->value;
    }
}
