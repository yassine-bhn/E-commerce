<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

/**
 * An image reference rule the dashboard broke (spec-run-19 R9, sub-category pictures). Thrown
 * by the domain, mapped to a 422 by the Infrastructure controller; the domain never knows
 * anything about HTTP.
 */
final class CatalogImageRejectedException extends \DomainException
{
    public static function emptyReference(): self
    {
        return new self('An image reference cannot be empty.');
    }

    public static function externalUrl(string $path): self
    {
        return new self(sprintf(
            'An image must reference a dashboard upload, not an external URL: "%s".',
            $path,
        ));
    }

    public static function outsideUploadsArea(string $path, string $uploadsPrefix): self
    {
        return new self(sprintf(
            'An image must live under the dashboard uploads area "%s": "%s".',
            $uploadsPrefix,
            $path,
        ));
    }

    public static function notAnUploadedImage(string $path): self
    {
        return new self(sprintf(
            'An image must be one of the JPEG, PNG or WebP formats the dashboard upload produces: "%s".',
            $path,
        ));
    }
}
