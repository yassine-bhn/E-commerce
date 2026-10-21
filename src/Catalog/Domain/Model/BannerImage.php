<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

/**
 * R19/R20 — one slide of the site banner: a reference to an uploaded image plus its position.
 *
 * A slide never holds bytes: it holds the `path` the dashboard upload endpoint returned, and the only
 * thing this value object guarantees is that the reference points inside the uploads area at an image
 * that endpoint could have produced. Removing a slide therefore cannot delete a file (R22).
 *
 * The identity is assigned by the persistence step (`id` is null while a draft image has not been
 * stored yet) because the domain must stay framework-free (LAY-001) and cannot mint UUIDs itself.
 */
final readonly class BannerImage
{
    /** The public area `POST /api/v1/admin/catalog/uploads` writes into: `public` + this prefix. */
    public const string UPLOADS_PREFIX = '/uploads/';

    private const int MAX_LENGTH = 512;

    /** @var list<string> */
    private const array FORMATS = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(
        public ?string $id,
        public string $path,
        public int $sortOrder,
    ) {
    }

    /** A not-yet-persisted slide, positioned by the order the admin sent (R20). */
    public static function draft(string $path, int $sortOrder): self
    {
        return new self(null, self::referenceTo($path), $sortOrder);
    }

    /** A slide as it already exists in the store — it was validated when it was written. */
    public static function restore(string $id, string $path, int $sortOrder): self
    {
        return new self($id, $path, $sortOrder);
    }

    public function withSortOrder(int $sortOrder): self
    {
        return new self($this->id, $this->path, $sortOrder);
    }

    /**
     * AGG-001: the reference rule belongs to the value object, so no caller can bypass it.
     */
    private static function referenceTo(string $path): string
    {
        $candidate = trim($path);

        if ('' === $candidate) {
            throw BannerRejectedException::emptyPath();
        }

        if (1 === preg_match('#^[a-z][a-z0-9+.\-]*://#i', $candidate) || str_starts_with($candidate, '//')) {
            throw BannerRejectedException::externalUrl($candidate);
        }

        if (
            !str_starts_with($candidate, self::UPLOADS_PREFIX)
            || mb_strlen($candidate) > self::MAX_LENGTH
            || str_contains($candidate, '..')
            || 1 === preg_match('~[\s\\\\\x00-\x1f\x7f?#]~', $candidate)
        ) {
            throw BannerRejectedException::outsideUploadsArea($candidate);
        }

        $format = strtolower((string) pathinfo($candidate, PATHINFO_EXTENSION));
        if (!in_array($format, self::FORMATS, true)) {
            throw BannerRejectedException::notAnUploadedImage($candidate);
        }

        return $candidate;
    }
}
