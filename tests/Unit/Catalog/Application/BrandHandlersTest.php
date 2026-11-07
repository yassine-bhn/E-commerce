<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Command\CreateBrand;
use App\Catalog\Application\Command\DeleteBrand;
use App\Catalog\Application\Command\RenameBrand;
use App\Catalog\Application\CommandHandler\CreateBrandHandler;
use App\Catalog\Application\CommandHandler\DeleteBrandHandler;
use App\Catalog\Application\CommandHandler\RenameBrandHandler;
use App\Catalog\Application\Port\BrandReader;
use App\Catalog\Application\Port\BrandStore;
use App\Catalog\Domain\Model\BrandAlreadyExistsException;
use App\Catalog\Domain\Model\BrandNotEmptyException;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use PHPUnit\Framework\TestCase;

/**
 * solid Task 4.9 — the brand handlers over in-memory fakes: the duplicate-create refusal, the
 * no-op rename, the re-index loop and the not-empty deletion guard.
 */
final class BrandHandlersTest extends TestCase
{
    public function testCreateRefusesADuplicateAndWritesNothing(): void
    {
        $reader = new FakeBrandReader(exists: true);
        $store = new FakeBrandStore();
        $submissions = new CatalogSubmissionFake();

        try {
            (new CreateBrandHandler($reader, $store, $submissions, $submissions))(new CreateBrand('Dalibor', 'admin-1'));
            self::fail('a duplicate brand must be refused');
        } catch (BrandAlreadyExistsException) {
            self::assertNull($store->created, 'a refused create never reaches the store');
            self::assertSame([], $submissions->calls, 'a refused create writes no submission (R45)');
        }
    }

    public function testCreateInsertsTheBrandAndApprovesTheSubmission(): void
    {
        $reader = new FakeBrandReader(exists: false);
        $store = new FakeBrandStore();
        $submissions = new CatalogSubmissionFake();

        (new CreateBrandHandler($reader, $store, $submissions, $submissions))(new CreateBrand('Dalibor', 'admin-1'));

        self::assertSame('Dalibor', $store->created);
        self::assertSame(
            ['method' => 'reviewerSave', 'args' => [CatalogSubmissionKind::Brand, 'Dalibor', 'admin-1']],
            $submissions->lastCall(),
            'the create is approved at once, like a category creation',
        );
    }

    public function testRenameDelegatesToTheStoreAndIndexesEveryRenamedProduct(): void
    {
        $store = new FakeBrandStore(renamedSlugs: ['pompes-alpha', 'pompes-beta']);
        $search = new FakeSearchIndex();

        (new RenameBrandHandler($store, $search))(new RenameBrand('Dalibor', 'Dalibor Plus', 'admin-1', true));

        self::assertSame('Dalibor Plus', $store->renamedTo);
        self::assertSame(['pompes-alpha', 'pompes-beta'], $search->synced, 'the brand travels inside every product document');
    }

    public function testASelfRenameIsANoOpThatTouchesNothing(): void
    {
        $store = new FakeBrandStore();
        $search = new FakeSearchIndex();

        (new RenameBrandHandler($store, $search))(new RenameBrand('Dalibor', 'Dalibor', 'admin-1', true));

        self::assertNull($store->renamedTo, 'a no-op rename writes nothing');
        self::assertSame([], $search->synced, 'a no-op rename re-indexes nothing');
    }

    public function testDeleteRefusesABrandThatStillCarriesProducts(): void
    {
        $reader = new FakeBrandReader(productCount: 3);
        $store = new FakeBrandStore();

        try {
            (new DeleteBrandHandler($reader, $store))(new DeleteBrand('Dalibor', 'super-admin'));
            self::fail('a brand with products must not be deleted');
        } catch (BrandNotEmptyException $exception) {
            self::assertStringContainsString('3', $exception->getMessage());
            self::assertNull($store->deleted, 'a refused delete never reaches the store');
        }
    }

    public function testDeleteReachesTheStoreWhenNoProductCarriesTheBrand(): void
    {
        $reader = new FakeBrandReader(productCount: 0);
        $store = new FakeBrandStore();

        (new DeleteBrandHandler($reader, $store))(new DeleteBrand('Dalibor', 'super-admin'));

        self::assertSame('Dalibor', $store->deleted);
        // the submission removal and the audit entry are the store's workflow — pinned by the
        // BrandStore integration test
    }
}

final class FakeBrandReader implements BrandReader
{
    public function __construct(
        private readonly bool $exists = false,
        private readonly int $productCount = 0,
    ) {
    }

    public function exists(string $name): bool
    {
        return $this->exists;
    }

    public function countProducts(string $name): int
    {
        return $this->productCount;
    }
}

final class FakeBrandStore implements BrandStore
{
    public ?string $created = null;

    public ?string $renamedTo = null;

    public ?string $deleted = null;

    /** @var list<string> */
    public array $renamedSlugs;

    /** @param list<string> $renamedSlugs */
    public function __construct(array $renamedSlugs = [])
    {
        $this->renamedSlugs = $renamedSlugs;
    }

    public function create(string $name): void
    {
        $this->created = $name;
    }

    public function rename(string $old, string $new, string $actorId, bool $actorIsSuperAdmin): array
    {
        $this->renamedTo = $new;

        return $this->renamedSlugs;
    }

    public function delete(string $name, string $actorId): void
    {
        $this->deleted = $name;
    }
}

final class FakeSearchIndex implements \App\Shared\Application\Port\ProductSearchIndexPort
{
    /** @var list<string> */
    public array $synced = [];

    /** @var list<string> */
    public array $forgotten = [];

    public function sync(string $slug): void
    {
        $this->synced[] = $slug;
    }

    public function forget(string $slug): void
    {
        $this->forgotten[] = $slug;
    }
}
