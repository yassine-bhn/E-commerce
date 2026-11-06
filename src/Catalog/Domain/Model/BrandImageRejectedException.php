<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

/**
 * spec-run-19 R9 — a brand image rule the dashboard broke. Thrown by the domain, mapped to a
 * 422 by the Infrastructure controller; the domain never knows anything about HTTP.
 */
final class BrandImageRejectedException extends \DomainException
{
    public static function emptyReference(): self
    {
        return new self('A brand image reference cannot be empty.');
    }

    public static function externalUrl(string $path): self
    {
        return new self(sprintf(
            'A brand image must reference a dashboard upload, not an external URL: "%s".',
            $path,
        ));
    }

    public static function outsideBrandsArea(string $path): self
    {
        return new self(sprintf(
            'A brand image must live under the dashboard uploads area "%s": "%s".',
            BrandImageReference::UPLOADS_PREFIX,
            $path,
        ));
    }

    public static function notAnUploadedImage(string $path): self
    {
        return new self(sprintf(
            'A brand image must be one of the JPEG, PNG or WebP formats the dashboard upload produces: "%s".',
            $path,
        ));
    }
}
