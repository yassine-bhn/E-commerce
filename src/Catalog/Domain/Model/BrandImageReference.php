<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

/**
 * spec-run-19 R9 — one brand image: a reference to an uploaded file, exactly one per brand.
 *
 * Like {@see BannerImage}, a brand never holds bytes: it holds the `path` the dashboard upload
 * endpoint returned, and the only thing this value object guarantees is that the reference
 * points inside the `brands` uploads area at an image that endpoint could have produced
 * (JPEG/PNG/WebP, the banner whitelist). The bound is the `storefront_brand.image_path`
 * column: VARCHAR(255), nullable — the reference is optional.
 */
final readonly class BrandImageReference
{
    /** The public area `POST /api/v1/admin/catalog/uploads?folder=brands` writes into. */
    public const string UPLOADS_PREFIX = '/uploads/brands/';

    /** The `storefront_brand.image_path` column bound: VARCHAR(255). */
    private const int MAX_LENGTH = 255;

    /** @var list<string> */
    private const array FORMATS = ['jpg', 'jpeg', 'png', 'webp'];

    public string $path;

    /** AGG-001: the reference rule belongs to the value object, so no caller can bypass it. */
    public function __construct(string $raw)
    {
        $candidate = trim($raw);

        if ('' === $candidate) {
            throw BrandImageRejectedException::emptyReference();
        }

        if (1 === preg_match('#^[a-z][a-z0-9+.\-]*://#i', $candidate) || str_starts_with($candidate, '//')) {
            throw BrandImageRejectedException::externalUrl($candidate);
        }

        if (
            !str_starts_with($candidate, self::UPLOADS_PREFIX)
            || mb_strlen($candidate) > self::MAX_LENGTH
            || str_contains($candidate, '..')
            || 1 === preg_match('~[\s\\\\\x00-\x1f\x7f?#]~', $candidate)
        ) {
            throw BrandImageRejectedException::outsideBrandsArea($candidate);
        }

        $format = strtolower((string) pathinfo($candidate, PATHINFO_EXTENSION));
        if (!in_array($format, self::FORMATS, true)) {
            throw BrandImageRejectedException::notAnUploadedImage($candidate);
        }

        $this->path = $candidate;
    }
}
