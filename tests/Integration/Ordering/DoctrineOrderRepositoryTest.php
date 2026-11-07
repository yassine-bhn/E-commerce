<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

use App\Tests\Integration\Contract\AbstractOrderRepositoryContractTest;

/**
 * LSP-001 — the OrderRepository contract bound to the Doctrine adapter against PostgreSQL.
 */
final class DoctrineOrderRepositoryTest extends AbstractOrderRepositoryContractTest
{
    protected function tearDown(): void
    {
        foreach ($this->createdOrderIds as $orderId) {
            $this->cleanup($orderId);
        }
        $this->createdOrderIds = [];

        parent::tearDown();
    }
}
