<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Tests\Integration\Contract\AbstractCatalogSubmissionRepositoryContractTest;

/**
 * LSP-001 — the CatalogSubmissionRepository contract bound to the DBAL store against PostgreSQL.
 */
final class CatalogSubmissionRepositoryContractTest extends AbstractCatalogSubmissionRepositoryContractTest
{
    protected function tearDown(): void
    {
        $this->cleanup();

        parent::tearDown();
    }
}
