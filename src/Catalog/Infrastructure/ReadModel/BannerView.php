<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

/**
 * R18/R20/R21 — the banner as one variable-length ordered slide set. The same view serves the admin
 * dashboard (which needs the slide identities to remove or reorder one) and the public read (which
 * only ever sees the ordered references).
 */
final readonly class BannerView
{
    /** @param list<BannerImageView> $images */
    public function __construct(
        public array $images,
    ) {
    }

    public function count(): int
    {
        return count($this->images);
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_map(static fn (BannerImageView $image): string => $image->path, $this->images);
    }

    /**
     * The dashboard shape: `{items: […], count: n}` in the stored order, following the `{items: …}`
     * convention the admin reads already use.
     *
     * @return array<string, mixed>
     */
    public function toAdminApi(): array
    {
        return [
            'items' => array_map(static fn (BannerImageView $image): array => $image->toArray(), $this->images),
            'count' => $this->count(),
        ];
    }

    /**
     * The public shape (R21): a plain ordered array of image references, `[]` when the admin has
     * removed every slide — a documented empty answer, never a synthetic fallback image.
     *
     * @return array<string, mixed>
     */
    public function toPublicApi(): array
    {
        return ['images' => $this->paths()];
    }
}
