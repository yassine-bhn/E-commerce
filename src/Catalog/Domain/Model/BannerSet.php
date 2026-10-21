<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\AggregateRoot;

/**
 * ADR-0008 — the single site-level banner is one ordered set of image references.
 *
 * The set is the aggregate: cardinality and order are properties of the set as a whole, so they are
 * enforced here (AGG-001) and never in a controller. A slide is only ever a reference to a file the
 * dashboard upload endpoint produced; removing one from this set never touches the file (R22).
 */
final class BannerSet extends AggregateRoot
{
    /** `storefront_banner` is a singleton row: the migration seeds it with `CHECK (id = 1)` (ADR-0008). */
    public const int SITE_BANNER_ID = 1;

    /** @var list<BannerImage> */
    private array $images;

    /** @param list<BannerImage> $images */
    private function __construct(array $images)
    {
        $this->images = $images;
    }

    /**
     * The whole set as the admin selected it (R18): every path becomes a slide, in the given order.
     *
     * @param list<string> $paths
     */
    public static function fromPaths(array $paths): self
    {
        if (count($paths) < BannerRejectedException::MIN_IMAGES || count($paths) > BannerRejectedException::MAX_IMAGES) {
            throw BannerRejectedException::cardinality(count($paths));
        }

        $images = [];
        /** @var array<string, bool> $seen */
        $seen = [];
        foreach ($paths as $index => $path) {
            $image = BannerImage::draft($path, $index);
            if (isset($seen[$image->path])) {
                throw BannerRejectedException::duplicatePath($image->path);
            }
            $seen[$image->path] = true;
            $images[] = $image;
        }

        return new self($images);
    }

    /**
     * The set as it is stored today — no invariant is re-checked, an existing row was validated when
     * it was written, and the set may legitimately hold fewer than the minimum between two edits.
     *
     * @param list<BannerImage> $images
     */
    public static function reconstitute(array $images): self
    {
        return new self($images);
    }

    public function count(): int
    {
        return count($this->images);
    }

    /**
     * R18: dropping one reference shrinks the set. The file it pointed at is left exactly where it is
     * (R22) — this set only ever holds references.
     */
    public function remove(string $imageId): void
    {
        $kept = [];
        foreach ($this->images as $image) {
            if ((string) $image->id !== $imageId) {
                $kept[] = $image;
            }
        }

        if (count($kept) === count($this->images)) {
            throw BannerImageNotFoundException::withId($imageId);
        }

        $this->images = $kept;
    }

    /**
     * R20: an explicit reorder rewrites the sequence densely, so the order the admin dragged the
     * slides into is the order every read returns.
     *
     * @param list<string> $imageIds
     */
    public function reorder(array $imageIds): void
    {
        if (count($imageIds) !== count($this->images) || count(array_unique($imageIds)) !== count($imageIds)) {
            throw BannerRejectedException::reorderMismatch(count($this->images), count($imageIds));
        }

        $byId = [];
        foreach ($this->images as $image) {
            $byId[(string) $image->id] = $image;
        }

        $ordered = [];
        foreach ($imageIds as $sortOrder => $imageId) {
            if (!isset($byId[$imageId])) {
                throw BannerImageNotFoundException::withId($imageId);
            }
            $ordered[] = $byId[$imageId]->withSortOrder($sortOrder);
        }

        $this->images = $ordered;
    }

    /** @return list<BannerImage> */
    public function images(): array
    {
        return $this->images;
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_map(static fn (BannerImage $image): string => $image->path, $this->images);
    }
}
