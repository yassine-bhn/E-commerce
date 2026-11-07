<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Tests\Integration\Contract\AbstractProductRepositoryContractTest;

/**
 * LSP-001 — the ProductRepository contract bound to the Doctrine adapter against PostgreSQL.
 */
final class DoctrineProductRepositoryTest extends AbstractProductRepositoryContractTest
{
    protected function tearDown(): void
    {
        foreach ($this->createdProductIds as $productId) {
            $this->cleanup($productId);
        }
        $this->createdProductIds = [];

        parent::tearDown();
    }
}
