<?php

declare(strict_types=1);

namespace App\Identity\Application\Query;

final readonly class ListQuoteRequestsForUser
{
    public function __construct(public string $userId)
    {
    }
}
