<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

/**
 * One catalog image: a reference to an uploaded file, never bytes. Like {@see BannerImage},
 * the record holds the `path` the dashboard upload endpoint returned, and the only thing this
 * value object guarantees is that the reference points inside the uploads area of its record
 * kind at an image that endpoint could have produced (JPEG/PNG/WebP, the banner whitelist).
 *
 * spec-run-19 R9 introduced the rule for the brand image; the sub-category picture (the
 * `image` key of a `storefront_category.subcategories` node) is the second record kind, so
 * the allowed area is the one constructor argument. The bound is the reference column each
 * record kind stores: VARCHAR(255) everywhere today.
 */
final readonly class UploadedImageReference
{
    /** The public area `POST /api/v1/admin/catalog/uploads?folder=brands` writes into. */
    public const string BRANDS = '/uploads/brands/';

    /** The public area `POST /api/v1/admin/catalog/uploads?folder=categories` writes into. */
    public const string CATEGORIES = '/uploads/categories/';

    /** The `…image_path` / node `image` bound: VARCHAR(255). */
    private const int MAX_LENGTH = 255;

    /** @var list<string> */
    private const array FORMATS = ['jpg', 'jpeg', 'png', 'webp'];

    public string $path;

    /** AGG-001: the reference rule belongs to the value object, so no caller can bypass it. */
    public function __construct(string $raw, string $uploadsPrefix)
    {
        $candidate = trim($raw);

        if ('' === $candidate) {
            throw CatalogImageRejectedException::emptyReference();
        }

        if (1 === preg_match('#^[a-z][a-z0-9+.\-]*://#i', $candidate) || str_starts_with($candidate, '//')) {
            throw CatalogImageRejectedException::externalUrl($candidate);
        }

        if (
            !str_starts_with($candidate, $uploadsPrefix)
            || mb_strlen($candidate) > self::MAX_LENGTH
            || str_contains($candidate, '..')
            || 1 === preg_match('~[\s\\\\\x00-\x1f\x7f?#]~', $candidate)
        ) {
            throw CatalogImageRejectedException::outsideUploadsArea($candidate, $uploadsPrefix);
        }

        $format = strtolower((string) pathinfo($candidate, PATHINFO_EXTENSION));
        if (!in_array($format, self::FORMATS, true)) {
            throw CatalogImageRejectedException::notAnUploadedImage($candidate);
        }

        $this->path = $candidate;
    }
}
