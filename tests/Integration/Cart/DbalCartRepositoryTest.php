<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cart;

use App\Tests\Integration\Contract\AbstractCartRepositoryContractTest;

/**
 * LSP-001 — the CartRepository contract bound to the DBAL adapter against PostgreSQL.
 */
final class DbalCartRepositoryTest extends AbstractCartRepositoryContractTest
{
    protected function tearDown(): void
    {
        foreach ($this->createdCustomerIds as $customerId) {
            $this->cleanup($customerId);
        }
        $this->createdCustomerIds = [];

        parent::tearDown();
    }
}
