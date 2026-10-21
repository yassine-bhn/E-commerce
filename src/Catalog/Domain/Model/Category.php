<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

/**
 * A storefront category row (`storefront_category`) and its sub-category tree.
 *
 * A sub-category is a node of this row's `subcategories` JSON tree, never a child
 * row (ADR-0006 / spec Q1). The tree shape is `[{label, children?}, …]` and its
 * limits are the ones already enforced by `SubcategoryTree` (depth <= 4,
 * label <= 128 chars, unique label among siblings), so every taxonomy write obeys
 * the same rules (spec R3).
 */
final class Category
{
    public const int MAX_LABEL_LENGTH = 128;

    /** @param array<array-key, mixed> $subcategories */
    private function __construct(
        public readonly string $slug,
        public readonly string $label,
        public readonly array $subcategories = [],
        public readonly bool $quoteEligible = false,
        public readonly bool $hidden = false,
        public readonly int $sortOrder = 0,
        public readonly ?string $parentSlug = null,
    ) {
    }

    /**
     * A new top-level category: visible, with the given sub-level tree.
     */
    public static function createRoot(string $slug, string $label, mixed $subcategories = [], bool $quoteEligible = false): self
    {
        return new self(
            slug: trim($slug),
            label: self::label($label),
            subcategories: CategorySubtree::normalize($subcategories),
            quoteEligible: $quoteEligible,
        );
    }

    /**
     * The state already stored in the table, trusted as-is: a legacy tree may predate
     * today's limits, so it is kept instead of being re-validated.
     */
    public static function restore(
        string $slug,
        string $label,
        mixed $subcategories = [],
        bool $quoteEligible = false,
        bool $hidden = false,
        int $sortOrder = 0,
        ?string $parentSlug = null,
    ): self {
        return new self($slug, $label, is_array($subcategories) ? $subcategories : [], $quoteEligible, $hidden, $sortOrder, $parentSlug);
    }

    /**
     * The row a handler read back for the category it was asked to change.
     * `false` means there is no such category: that is a domain-level not-found, never a
     * silent no-op, so an idempotent-looking endpoint cannot hide a typo'ed slug.
     *
     * @param array<string, mixed>|false $row
     */
    public static function fromStoredRow(array|false $row, string $slug): self
    {
        if (false === $row) {
            throw CategoryNotFoundException::forSlug($slug);
        }

        return self::fromRow($row);
    }

    /**
     * The row as the DBAL handlers read it back from `storefront_category`.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $subcategories = $row['subcategories'] ?? '[]';

        return self::restore(
            slug: (string) $row['slug'],
            label: (string) $row['label'],
            subcategories: is_string($subcategories) ? json_decode($subcategories, true) : $subcategories,
            quoteEligible: filter_var($row['quote_eligible'] ?? false, FILTER_VALIDATE_BOOLEAN),
            hidden: filter_var($row['hidden'] ?? false, FILTER_VALIDATE_BOOLEAN),
            sortOrder: (int) ($row['sort_order'] ?? 0),
            parentSlug: isset($row['parent_slug']) ? (string) $row['parent_slug'] : null,
        );
    }

    public static function label(string $label): string
    {
        $label = trim($label);
        if ('' === $label) {
            throw CategoryLabelInvalidException::empty();
        }

        if (mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            throw CategoryLabelInvalidException::tooLong(mb_strlen($label));
        }

        return $label;
    }

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    /**
     * A sub-category node of this row's tree: at the top level, or under `$parentNode`.
     */
    public function withSubLevel(string $label, ?string $parentNode = null): self
    {
        return self::restore(
            $this->slug,
            $this->label,
            CategorySubtree::add($this->subcategories, $label, $parentNode),
            $this->quoteEligible,
            $this->hidden,
            $this->sortOrder,
            $this->parentSlug,
        );
    }

    /**
     * The editable content of the category: label, flags and the whole sub-level tree.
     * The slug and the storefront visibility are not part of this change (the slug is the
     * key products point at, hiding is its own endpoint).
     */
    public function withContent(string $label, mixed $subcategories, bool $quoteEligible): self
    {
        return self::restore(
            $this->slug,
            self::label($label),
            CategorySubtree::normalize($subcategories),
            $quoteEligible,
            $this->hidden,
            $this->sortOrder,
            $this->parentSlug,
        );
    }

    public function hide(): self
    {
        return self::restore($this->slug, $this->label, $this->subcategories, $this->quoteEligible, true, $this->sortOrder, $this->parentSlug);
    }

    public function show(): self
    {
        return self::restore($this->slug, $this->label, $this->subcategories, $this->quoteEligible, false, $this->sortOrder, $this->parentSlug);
    }

    public function subLevelCount(): int
    {
        return count(CategorySubtree::labels($this->subcategories));
    }

    /** @return array<array-key, mixed> */
    public function subcategories(): array
    {
        return $this->subcategories;
    }

    /** @return list<string> */
    public function subLevelLabels(): array
    {
        return CategorySubtree::labels($this->subcategories);
    }

    public function subcategoriesJson(): string
    {
        return json_encode($this->subcategories, JSON_THROW_ON_ERROR);
    }

    /**
     * A tree may only drop a sub-level no product of this category still points at,
     * otherwise the catalog and the order snapshots would disagree (ECO-006).
     *
     * @param list<string> $labelsAlreadyUsedByProducts
     */
    public function coversSubLevels(array $labelsAlreadyUsedByProducts): void
    {
        $missing = array_values(array_diff($labelsAlreadyUsedByProducts, $this->subLevelLabels()));
        if ([] !== $missing) {
            throw CategorySublevelInUseException::forLabels($this->slug, $missing);
        }
    }

    /**
     * Deleting a category must never orphan a sub-level node or a product (spec R4, RG1.5):
     * the children counted here are the nodes of this row's tree, plus the legacy
     * `parent_slug` rows a pre-Q1 taxonomy may still have.
     */
    public function ensureDeletable(int $legacyChildRows, int $productCount): void
    {
        $subcategories = $this->subLevelCount() + $legacyChildRows;
        if ($subcategories > 0 || $productCount > 0) {
            throw CategoryNotEmptyException::forCategory($this->slug, $subcategories, $productCount);
        }
    }
}
