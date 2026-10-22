<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

use App\Catalog\Domain\Model\Category;

/**
 * One row of the admin category list (spec R8) — a read DTO, never an ORM entity.
 */
final readonly class CategoryView implements \JsonSerializable
{
    /**
     * @param array<array-key, mixed> $subcategories
     */
    public function __construct(
        public string $slug,
        public string $label,
        public ?string $parentSlug,
        public array $subcategories,
        public int $subLevelCount,
        public bool $hidden,
        public bool $quoteEligible,
        public int $productCount,
        public int $sortOrder,
    ) {
    }

    /**
     * The category row as the list read selects it, `product_count` included. The tree is
     * decoded by the domain model so the admin list and the write handlers agree on one
     * single shape.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $category = Category::fromRow($row);

        return new self(
            slug: $category->slug,
            label: $category->label,
            parentSlug: $category->parentSlug,
            subcategories: $category->subcategories(),
            subLevelCount: $category->subLevelCount(),
            hidden: $category->isHidden(),
            quoteEligible: $category->quoteEligible,
            productCount: (int) ($row['product_count'] ?? 0),
            sortOrder: $category->sortOrder,
        );
    }

    /**
     * The dashboard payload: the tree it edits plus the counts that decide whether a
     * category can still be deleted.
     *
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        return [
            'slug' => $this->slug,
            'label' => $this->label,
            'parentSlug' => $this->parentSlug,
            'subcategories' => $this->subcategories,
            'subLevelCount' => $this->subLevelCount,
            'hidden' => $this->hidden,
            'quoteEligible' => $this->quoteEligible,
            'productCount' => $this->productCount,
            'sortOrder' => $this->sortOrder,
        ];
    }

    /**
     * Same payload inside the `{items, meta}` page envelope: the list keeps the exact shape
     * the dashboard already consumes.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toApi();
    }
}
