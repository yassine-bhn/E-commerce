<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

use App\Catalog\Domain\Model\CatalogSubmissionKind;

/**
 * spec-run-8 R5/R6/R15/R16/R21 — one submission as the admin workflow reads it.
 *
 * This is the approval projection of a catalog row, not a second copy of it: the content stays in
 * the record, the status, the submitter, the reviewer, both moments and the rejection reason come
 * from the columns this workflow added. It is never returned by a public endpoint (R33).
 *
 * The author and reviewer names are the display labels of the two account ids: the queue is read
 * by people, not by uuid. `image` is a product-only convenience for the same reason — a category
 * row has none, and the reviewer then sees the thumbnail of what they are about to approve.
 */
final readonly class CatalogSubmissionView implements \JsonSerializable
{
    public function __construct(
        public CatalogSubmissionKind $kind,
        public string $slug,
        public string $label,
        public string $approvalStatus,
        public ?string $submittedBy,
        public ?string $submittedAt,
        public ?string $reviewedBy,
        public ?string $reviewedAt,
        public ?string $rejectionReason,
        public ?bool $published = null,
        public ?string $categorySlug = null,
        public ?string $image = null,
        /** @var list<string> The pictures attached after `image`; empty for a category. */
        public array $additionalImages = [],
        public ?string $submittedByName = null,
        public ?string $submittedByEmail = null,
        public ?string $reviewedByName = null,
        public ?string $reviewedByEmail = null,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(CatalogSubmissionKind $kind, array $row): self
    {
        return new self(
            kind: $kind,
            slug: (string) $row['slug'],
            label: (string) $row['label'],
            approvalStatus: (string) $row['approval_status'],
            submittedBy: self::text($row['submitted_by'] ?? null),
            submittedAt: self::moment($row['submitted_at'] ?? null),
            reviewedBy: self::text($row['reviewed_by'] ?? null),
            reviewedAt: self::moment($row['reviewed_at'] ?? null),
            rejectionReason: self::text($row['rejection_reason'] ?? null),
            published: null === ($row['published'] ?? null) ? null : filter_var($row['published'], FILTER_VALIDATE_BOOLEAN),
            categorySlug: self::text($row['category_slug'] ?? null),
            image: self::text($row['image'] ?? null),
            additionalImages: self::paths($row['additional_images'] ?? null),
            submittedByName: self::text($row['author_name'] ?? null),
            submittedByEmail: self::text($row['author_email'] ?? null),
            reviewedByName: self::text($row['reviewer_name'] ?? null),
            reviewedByEmail: self::text($row['reviewer_email'] ?? null),
        );
    }

    public function isApproved(): bool
    {
        return 'APPROVED' === $this->approvalStatus;
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'type' => $this->kind->value,
            'slug' => $this->slug,
            'label' => $this->label,
            'approvalStatus' => $this->approvalStatus,
            'submittedBy' => $this->submittedBy,
            'submittedAt' => $this->submittedAt,
            'reviewedBy' => $this->reviewedBy,
            'reviewedAt' => $this->reviewedAt,
            'rejectionReason' => $this->rejectionReason,
            'published' => $this->published,
            'categorySlug' => $this->categorySlug,
            'image' => $this->image,
            'additionalImages' => $this->additionalImages,
            'submittedByName' => $this->submittedByName,
            'submittedByEmail' => $this->submittedByEmail,
            'reviewedByName' => $this->reviewedByName,
            'reviewedByEmail' => $this->reviewedByEmail,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toApi();
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * The gallery read back from the JSON column; a category row has none and the queue then shows
     * only what the record can have — the same answer as `image`.
     *
     * @return list<string>
     */
    private static function paths(mixed $value): array
    {
        if (is_string($value) && '' !== $value) {
            $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        }
        if (!is_array($value)) {
            return [];
        }

        $paths = [];
        foreach ($value as $path) {
            if (is_string($path) && '' !== $path) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    private static function moment(mixed $value): ?string
    {
        if (!is_string($value) || '' === $value) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format(\DATE_ATOM);
        } catch (\Exception) {
            return $value;
        }
    }
}
