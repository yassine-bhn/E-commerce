<?php

declare(strict_types=1);

namespace App\Tests\Integration\Contract;

use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Repository\CatalogSubmissionRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * LSP-001 — the contract the CatalogSubmissionRepository adapter must honour (solid Task 5.1):
 * find answers null for a missing key and the stored status/owner for an existing one. The rows
 * here are seeded directly (the write contract is DbalCatalogSubmissionStore's, already covered).
 */
abstract class AbstractCatalogSubmissionRepositoryContractTest extends KernelTestCase
{
    /** @var list<string> */
    protected array $createdSlugs = [];

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function repository(): CatalogSubmissionRepository
    {
        $repository = static::getContainer()->get(CatalogSubmissionRepository::class);
        \assert($repository instanceof CatalogSubmissionRepository);

        return $repository;
    }

    protected function seedSubmission(string $slug, string $status, string $owner): void
    {
        $this->createdSlugs[] = $slug;
        self::connection()->insert('catalog_submission', [
            'kind' => 'products',
            'slug' => $slug,
            'approval_status' => $status,
            'submitted_by' => $owner,
            'submitted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP'),
        ]);
    }

    protected function cleanup(): void
    {
        if ([] !== $this->createdSlugs) {
            self::connection()->executeStatement(
                'DELETE FROM catalog_submission WHERE kind = :kind AND slug IN (:slugs)',
                ['kind' => 'products', 'slugs' => $this->createdSlugs],
                ['slugs' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }

        $this->createdSlugs = [];
    }

    public function testFindReturnsNullForAMissingKey(): void
    {
        try {
            self::assertNull($this->repository()->find(CatalogSubmissionKind::Product, Uuid::v7()->toRfc4122()));
        } finally {
            $this->cleanup();
        }
    }

    public function testFindReturnsTheStoredStatusAndOwner(): void
    {
        $slug = 'contract-submission-'.substr(Uuid::v7()->toRfc4122(), 0, 8);
        $this->seedSubmission($slug, 'PENDING_APPROVAL', 'admin-1');

        try {
            $submission = $this->repository()->find(CatalogSubmissionKind::Product, $slug);

            self::assertNotNull($submission);
            self::assertSame($slug, $submission->slug());
            self::assertSame('PENDING_APPROVAL', $submission->status()->value);
            self::assertSame('admin-1', $submission->submittedBy());
        } finally {
            $this->cleanup();
        }
    }

    private static function connection(): Connection
    {
        self::bootKernel();

        $connection = static::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        return $connection;
    }
}
