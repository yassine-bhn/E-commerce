<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\Model\Product;
use App\Catalog\Domain\Model\ProductId;

interface ProductRepository
{
    public function get(ProductId $id): Product;

    public function save(Product $product): void;
}
