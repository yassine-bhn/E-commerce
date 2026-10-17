<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class GetStorefrontProductBySlug
{
    public function __construct(public string $slug)
    {
    }
}
