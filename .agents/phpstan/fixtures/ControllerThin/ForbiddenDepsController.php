<?php

declare(strict_types=1);

namespace App\Cart\Infrastructure\Http;

use Doctrine\DBAL\Connection;

final class ForbiddenDepsController
{
    public function __construct(
        private Connection $connection,
        private ProductRepository $products,
        private OrderViewFinder $views,
    ) {
    }
}

final class ProductRepository
{
}

final class OrderViewFinder
{
}
