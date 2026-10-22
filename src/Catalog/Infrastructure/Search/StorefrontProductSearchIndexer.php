<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Search;

use Doctrine\DBAL\Connection;
use Meilisearch\Client;

/**
 * Single writer of the `storefront_products` Meilisearch index (ECO-008, ADR-0005).
 *
 * The index is sourced from the denormalized `storefront_product_view` read model, never from
 * the write model: Meilisearch is a projection target like any other read table (PRF-003).
 *
 * {@see self::indexSlug()} / {@see self::removeSlug()} / {@see self::ensureSettings()} /
 * {@see self::rebuildAll()} surface transport errors so the reindex command can fail loudly;
 * write-side callers use the best-effort {@see self::sync()} / {@see self::forget()} so a
 * search outage never breaks an admin write.
 */
final class StorefrontProductSearchIndexer
{
    public const string INDEX_NAME = 'storefront_products';

    public const array SEARCHABLE_ATTRIBUTES = ['name', 'brand', 'ref', 'subcategory', 'category_slug', 'material'];

    public const array FILTERABLE_ATTRIBUTES = [
        'category_slug', 'subcategory', 'brand', 'is_new', 'is_best', 'price_minor', 'stock', 'material', 'rating',
    ];

    public const array SORTABLE_ATTRIBUTES = ['price_minor', 'rating', 'reviews', 'name'];

    public const int MAX_TOTAL_HITS = 5000;

    private const int BATCH_SIZE = 500;

    private const string SELECT = 'SELECT slug, name, brand, ref, category_slug, subcategory, price_minor,
            old_price_minor, rating, reviews, is_new, is_best, stock, material, dims, image_path
        FROM storefront_product_view';

    public function __construct(
        private readonly Connection $connection,
        private readonly Client $client,
    ) {
    }

    public function ensureSettings(): void
    {
        $this->client->index(self::INDEX_NAME)->updateSettings([
            'searchableAttributes' => self::SEARCHABLE_ATTRIBUTES,
            'filterableAttributes' => self::FILTERABLE_ATTRIBUTES,
            'sortableAttributes' => self::SORTABLE_ATTRIBUTES,
            'pagination' => ['maxTotalHits' => self::MAX_TOTAL_HITS],
        ]);
    }

    public function indexSlug(string $slug): void
    {
        $row = $this->connection->fetchAssociative(self::SELECT.' WHERE slug = :slug', ['slug' => $slug]);

        if (false === $row) {
            $this->removeSlug($slug);

            return;
        }

        $this->client->index(self::INDEX_NAME)->addDocuments([self::document($row)], 'id');
    }

    public function removeSlug(string $slug): void
    {
        $this->client->index(self::INDEX_NAME)->deleteDocument($slug);
    }

    /**
     * Drops and rebuilds the whole index from the read model; returns the number of documents sent.
     *
     * The rebuild waits for its own task so the command exits with a searchable index instead of
     * a queue entry (an explicit reindex is not latency sensitive).
     */
    public function rebuildAll(): int
    {
        $this->ensureSettings();

        $index = $this->client->index(self::INDEX_NAME);
        $tasks = [$index->deleteAllDocuments()];

        $indexed = 0;
        foreach ($this->documents() as $documents) {
            $tasks[] = $index->addDocuments($documents, 'id');
            $indexed += count($documents);
        }

        $last = end($tasks);
        $taskUid = is_array($last) ? ($last['taskUid'] ?? null) : null;
        if (is_int($taskUid)) {
            $this->client->waitForTask($taskUid);
        }

        return $indexed;
    }

    /** Best-effort refresh used by the event projection: a search outage must not fail the consumer. */
    public function sync(string $slug): void
    {
        try {
            $this->indexSlug($slug);
        } catch (\Throwable) {
            // Meilisearch is optional: the read model stays authoritative (PRF-003).
        }
    }

    /** Best-effort removal used by the admin delete path. */
    public function forget(string $slug): void
    {
        try {
            $this->removeSlug($slug);
        } catch (\Throwable) {
            // Meilisearch is optional: the read model stays authoritative (PRF-003).
        }
    }

    /**
     * @return \Generator<int, list<array<string, mixed>>>
     */
    private function documents(): \Generator
    {
        /** @var list<array<string, mixed>> $batch */
        $batch = [];

        foreach ($this->connection->iterateAssociative(self::SELECT.' ORDER BY slug ASC') as $row) {
            $batch[] = self::document($row);

            if (self::BATCH_SIZE === count($batch)) {
                yield $batch;
                $batch = [];
            }
        }

        if ([] !== $batch) {
            yield $batch;
        }
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function document(array $row): array
    {
        return [
            'id' => (string) $row['slug'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'brand' => (string) $row['brand'],
            'ref' => (string) $row['ref'],
            'category_slug' => (string) $row['category_slug'],
            'subcategory' => (string) $row['subcategory'],
            'price_minor' => (int) $row['price_minor'],
            'old_price_minor' => null === $row['old_price_minor'] ? null : (int) $row['old_price_minor'],
            'rating' => (float) $row['rating'],
            'reviews' => (int) $row['reviews'],
            'is_new' => (bool) $row['is_new'],
            'is_best' => (bool) $row['is_best'],
            'stock' => (string) $row['stock'],
            'material' => (string) $row['material'],
            'dims' => (string) $row['dims'],
            'image_path' => (string) $row['image_path'],
        ];
    }
}
