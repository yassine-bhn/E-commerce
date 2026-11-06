<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence;

use App\Catalog\Application\Port\BrandReader;
use App\Catalog\Application\Port\BrandStore;
use App\Catalog\Application\Port\CatalogAuditPort;
use App\Catalog\Application\Port\CatalogSubmissionDeleter;
use App\Catalog\Application\Port\CatalogSubmissionOwnerEditor;
use App\Catalog\Application\Service\SubmissionAccessPolicy;
use App\Catalog\Domain\Model\BrandAlreadyExistsException;
use App\Catalog\Domain\Model\BrandNotEmptyException;
use App\Catalog\Domain\Model\BrandNotFoundException;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use Doctrine\DBAL\Connection;

/**
 * solid Task 4.9 — the brand-table adapter. The rename is the one workflow write that changes a
 * record's identity: the row and every product carrying the name move together, the submission
 * follows through the submission store (the one owner of that table), and the audit entry lands
 * in the same transaction the command-bus middleware opened.
 */
final class DbalBrandStore implements BrandReader, BrandStore
{
    public function __construct(
        private Connection $connection,
        private SubmissionAccessPolicy $access,
        private CatalogSubmissionDeleter $submissionDeletions,
        private CatalogSubmissionOwnerEditor $submissions,
        private CatalogAuditPort $audit,
    ) {
    }

    public function exists(string $name): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM storefront_brand WHERE lower(name) = lower(:name)',
            ['name' => $name],
        );
    }

    public function countProducts(string $name): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM storefront_product_view WHERE brand = :name',
            ['name' => $name],
        );
    }

    public function create(string $name): void
    {
        $this->connection->executeStatement('INSERT INTO storefront_brand (name) VALUES (:name)', ['name' => $name]);
    }

    public function rename(string $old, string $new, string $actorId, bool $actorIsSuperAdmin): array
    {
        $this->access->authorize(CatalogSubmissionKind::Brand, $old, $actorId, $actorIsSuperAdmin);

        $clash = $this->connection->fetchOne(
            'SELECT 1 FROM storefront_brand WHERE lower(name) = lower(:new) AND name <> :old',
            ['new' => $new, 'old' => $old],
        );
        if (false !== $clash) {
            throw BrandAlreadyExistsException::forName($new);
        }

        $renamed = $this->connection->executeStatement(
            'UPDATE storefront_brand SET name = :new WHERE name = :old',
            ['new' => $new, 'old' => $old],
        );
        if (0 === $renamed) {
            throw BrandNotFoundException::forName($old);
        }

        $this->connection->executeStatement(
            'UPDATE storefront_product_view SET brand = :new WHERE brand = :old',
            ['new' => $new, 'old' => $old],
        );

        $this->submissions->renameSubject(CatalogSubmissionKind::Brand, $old, $new, $actorId);

        $this->audit->record(
            'CATALOG_BRAND_RENAMED',
            CatalogSubmissionKind::Brand->auditTarget(),
            $new,
            $actorId,
            sprintf('Renamed from "%s".', $old),
        );

        /** @var list<string> $slugs */
        $slugs = $this->connection->fetchFirstColumn(
            'SELECT slug FROM storefront_product_view WHERE brand = :name',
            ['name' => $new],
        );

        return $slugs;
    }

    public function delete(string $name, string $actorId): void
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM storefront_product_view WHERE brand = :name',
            ['name' => $name],
        );
        if ((int) $count > 0) {
            throw BrandNotEmptyException::forBrand($name, (int) $count);
        }

        $deleted = $this->connection->executeStatement('DELETE FROM storefront_brand WHERE name = :name', ['name' => $name]);
        if (0 === $deleted) {
            throw BrandNotFoundException::forName($name);
        }

        // Slug (name) reuse must not inherit a previous approval (R11).
        $this->submissionDeletions->delete(CatalogSubmissionKind::Brand, $name);

        $this->audit->record(
            'CATALOG_BRAND_DELETED',
            CatalogSubmissionKind::Brand->auditTarget(),
            $name,
            $actorId,
        );
    }
}
