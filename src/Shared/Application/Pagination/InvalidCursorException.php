<?php

declare(strict_types=1);

namespace App\Shared\Application\Pagination;

final class InvalidCursorException extends \InvalidArgumentException
{
    public static function create(): self
    {
        return new self('Invalid cursor');
    }
}
