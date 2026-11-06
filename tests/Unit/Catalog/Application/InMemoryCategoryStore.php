<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Port\CategoryCreator;
use App\Catalog\Application\Port\CategoryDeleter;
use App\Catalog\Application\Port\CategoryDeletionReader;
use App\Catalog\Application\Port\CategoryHierarchyReader;
use App\Catalog\Application\Port\CategoryReader;
use App\Catalog\Application\Port\CategoryTreeWriter;
use App\Catalog\Application\Port\CategoryVisibilityWriter;
use App\Catalog\Application\Service\SubmissionAccessPolicy;
use App\Catalog\Domain\Model\Category;
use PHPUnit\Framework\Assert;

/**
 * Inline fake (TDD-008) of the category ports a command handler talks to: it records every
 * write as a port call and replays the categories the handler would read back, so the
 * write-side rules can be tested without a database.
 */
final class InMemoryCategoryStore implements CategoryReader, CategoryCreator, CategoryTreeWriter, CategoryVisibilityWriter, CategoryDeleter, CategoryDeletionReader, CategoryHierarchyReader
{
    /** @var list<array{method: string, category?: Category, slug?: string, hidden?: bool}> */
    public array $writes = [];

    /** @var list<array<string, mixed>|false> */
    public array $rows = [];

    /** @var list<list<string>> */
    public array $usedSubLevels = [];

    /** @var list<array{children: int, products: int}> */
    public array $counts = [];

    /** @var array<string, string|null> */
    public array $hierarchy = [];

    public CatalogSubmissionFake $submissions;

    public ?\Throwable $failNextCreate = null;

    private bool $forbidWrites = false;

    public function __construct()
    {
        $this->submissions = new CatalogSubmissionFake();
    }

    /**
     * Any write issued after this call fails the test: a refused write must leave the
     * table untouched.
     */
    public function expectsNoWrite(): void
    {
        $this->forbidWrites = true;
    }

    public function find(string $slug): ?Category
    {
        $row = array_shift($this->rows);

        return false === $row || null === $row ? null : Category::fromStoredRow($row, $slug);
    }

    public function usedSubLevels(string $slug): array
    {
        return array_shift($this->usedSubLevels) ?? [];
    }

    public function nextSortOrder(): int
    {
        return 1;
    }

    public function create(Category $category): void
    {
        $this->guardWrite('create');

        if (null !== $this->failNextCreate) {
            $failure = $this->failNextCreate;
            $this->failNextCreate = null;

            throw $failure;
        }

        $this->writes[] = ['method' => 'create', 'category' => $category];
    }

    public function replaceContent(Category $category): void
    {
        $this->guardWrite('replaceContent');
        $this->writes[] = ['method' => 'replaceContent', 'category' => $category];
    }

    public function addSubLevel(Category $category): void
    {
        $this->guardWrite('addSubLevel');
        $this->writes[] = ['method' => 'addSubLevel', 'category' => $category];
    }

    public function setHidden(string $slug, bool $hidden): void
    {
        $this->guardWrite('setHidden');
        $this->writes[] = ['method' => 'setHidden', 'slug' => $slug, 'hidden' => $hidden];
    }

    public function counts(string $slug): array
    {
        return array_shift($this->counts) ?? ['children' => 0, 'products' => 0];
    }

    public function delete(string $slug): void
    {
        $this->guardWrite('delete');
        $this->writes[] = ['method' => 'delete', 'slug' => $slug];
    }

    public function parentMap(): array
    {
        return $this->hierarchy;
    }

    /**
     * The approval row {@see SubmissionAccessPolicy} reads for the slug under test, so a
     * handler test can stay about the write it makes.
     */
    public function submission(string $status = 'PENDING_APPROVAL', ?string $owner = null): void
    {
        $this->submissions->any(\App\Catalog\Domain\Model\ApprovalStatus::fromStorage($status), $owner);
    }

    public function accessPolicy(): SubmissionAccessPolicy
    {
        return new SubmissionAccessPolicy($this->submissions);
    }

    /** @return array{method: string, category?: Category, slug?: string, hidden?: bool}|null */
    public function lastWrite(): ?array
    {
        return [] === $this->writes ? null : $this->writes[count($this->writes) - 1];
    }

    /** The category the last tree/creation write carried, or null when none did. */
    public function lastWrittenCategory(): ?Category
    {
        foreach (array_reverse($this->writes) as $write) {
            if (isset($write['category'])) {
                return $write['category'];
            }
        }

        return null;
    }

    /** The slug the last slug-carrying write named, or null. */
    public function lastWrittenSlug(): ?string
    {
        foreach (array_reverse($this->writes) as $write) {
            if (isset($write['slug'])) {
                return (string) $write['slug'];
            }
        }

        return null;
    }

    /** The flag the last visibility write set, or null. */
    public function lastWrittenHidden(): ?bool
    {
        foreach (array_reverse($this->writes) as $write) {
            if (isset($write['hidden'])) {
                return (bool) $write['hidden'];
            }
        }

        return null;
    }

    /** @return list<string> */
    public function writtenMethods(): array
    {
        return array_map(static fn (array $write): string => (string) $write['method'], $this->writes);
    }

    private function guardWrite(string $method): void
    {
        if ($this->forbidWrites) {
            Assert::fail('a refused category write must not reach the store: '.$method);
        }
    }
}
