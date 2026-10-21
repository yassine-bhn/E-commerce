<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

/**
 * ERR-002 — a banner rule the dashboard broke. Thrown by the domain, mapped to a 4xx by the
 * Infrastructure controller; the domain never knows anything about HTTP.
 */
final class BannerRejectedException extends \DomainException
{
    public const int MIN_IMAGES = 1;
    public const int MAX_IMAGES = 10;

    public static function cardinality(int $selected): self
    {
        return new self(sprintf(
            'The banner must contain between %d and %d images, %d selected.',
            self::MIN_IMAGES,
            self::MAX_IMAGES,
            $selected,
        ));
    }

    public static function emptyPath(): self
    {
        return new self('A banner image reference cannot be empty.');
    }

    public static function externalUrl(string $path): self
    {
        return new self(sprintf(
            'A banner image must reference a dashboard upload, not an external URL: "%s".',
            $path,
        ));
    }

    public static function outsideUploadsArea(string $path): self
    {
        return new self(sprintf(
            'A banner image must live under the dashboard uploads area "%s": "%s".',
            BannerImage::UPLOADS_PREFIX,
            $path,
        ));
    }

    public static function notAnUploadedImage(string $path): self
    {
        return new self(sprintf(
            'A banner image must be one of the JPEG, PNG or WebP formats the dashboard upload produces: "%s".',
            $path,
        ));
    }

    /**
     * ADR-0008 leaves the duplicate policy to the handler layer: the same slide twice in a row reads
     * as a broken carousel, and the schema deliberately carries no UNIQUE so this is the only place
     * the rule can live.
     */
    public static function duplicatePath(string $path): self
    {
        return new self(sprintf('The same image cannot appear twice in one banner set: "%s".', $path));
    }

    public static function reorderMismatch(int $images, int $idsGiven): self
    {
        return new self(sprintf(
            'A banner reorder must list every image id exactly once: the banner holds %d images, %d ids were given.',
            $images,
            $idsGiven,
        ));
    }

    public static function fieldMustBeAList(string $field, string $ofWhat): self
    {
        return new self(sprintf('The "%s" field must be a JSON array of %s.', $field, $ofWhat));
    }

    public static function fieldMustContainStrings(string $field, string $ofItems): self
    {
        return new self(sprintf('The "%s" field must contain only %s.', $field, $ofItems));
    }
}
