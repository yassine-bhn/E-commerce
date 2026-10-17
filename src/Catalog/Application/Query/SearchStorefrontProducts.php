<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class SearchStorefrontProducts
{
    public function __construct(public string $query, public int $limit = 48)
    {
    }
}
