<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

/**
 * The sub-category tree stored as JSON inside one `storefront_category` row:
 * `[{label, children?}, …]` (ADR-0006 — a sub-category is a node of this tree, never a child row).
 *
 * Depth and label limits match the existing `SubcategoryTree` guard (spec R3); the tree is
 * read as it is stored, so the legacy flat `["Eau", "Tubes"]` shape keeps working.
 */
final class CategorySubtree
{
    public const int MAX_DEPTH = 4;

    /**
     * A stored tree (nodes or legacy plain strings) flattened into every label, depth first.
     *
     * @return list<string>
     */
    public static function labels(mixed $tree): array
    {
        if (!is_array($tree)) {
            return [];
        }

        $labels = [];
        foreach ($tree as $node) {
            if (is_string($node)) {
                $labels[] = $node;
                continue;
            }

            if (!is_array($node) || !isset($node['label']) || !is_string($node['label'])) {
                continue;
            }

            $labels[] = $node['label'];
            if (isset($node['children'])) {
                array_push($labels, ...self::labels($node['children']));
            }
        }

        return $labels;
    }

    /**
     * Validates and normalizes an untrusted tree: legacy strings become nodes, labels are
     * trimmed and bounded, siblings must be unique and the nesting depth is limited.
     *
     * @return list<array<string, mixed>>
     */
    public static function normalize(mixed $raw, int $depth = 1): array
    {
        if (!is_array($raw)) {
            throw CategorySublevelException::notAnArray();
        }

        if ($depth > self::MAX_DEPTH) {
            throw CategorySublevelException::tooDeep($depth);
        }

        $nodes = [];
        $seen = [];
        foreach (array_values($raw) as $item) {
            $label = self::labelOf($item);
            if (isset($seen[$label])) {
                throw CategorySublevelException::duplicatedSibling($label);
            }
            $seen[$label] = true;

            $node = ['label' => $label];
            $children = is_array($item) ? ($item['children'] ?? []) : [];
            if (is_array($children) && [] !== $children) {
                $node['children'] = self::normalize($children, $depth + 1);
            }

            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * Adds one node to a stored tree: at the top level, or as a child of the node
     * labelled `$parentLabel`. The result is validated as a whole, so a duplicated
     * sibling label or a fifth nesting level leaves the caller with nothing to store.
     *
     * @return list<array<string, mixed>>
     */
    public static function add(mixed $stored, string $label, ?string $parentLabel = null): array
    {
        $label = Category::label($label);
        $tree = self::copy($stored);
        $parentLabel = null === $parentLabel ? null : trim($parentLabel);

        if (null === $parentLabel || '' === $parentLabel) {
            $tree[] = ['label' => $label];

            return self::normalize($tree);
        }

        if (!in_array($parentLabel, self::labels($tree), true)) {
            throw CategorySublevelException::unknownParent($parentLabel);
        }

        $tree = self::insertUnder($tree, $parentLabel, $label);

        return self::normalize($tree);
    }

    /**
     * The stored tree as canonical nodes, legacy strings included, without re-validating
     * a taxonomy that was written before today's limits existed.
     *
     * @return list<array<string, mixed>>
     */
    private static function copy(mixed $tree): array
    {
        if (!is_array($tree)) {
            return [];
        }

        $nodes = [];
        foreach (array_values($tree) as $node) {
            if (is_string($node)) {
                $nodes[] = ['label' => $node];
                continue;
            }

            if (!is_array($node) || !isset($node['label']) || !is_string($node['label'])) {
                continue;
            }

            $copy = ['label' => $node['label']];
            $children = is_array($node['children'] ?? null) ? self::copy($node['children']) : [];
            if ([] !== $children) {
                $copy['children'] = $children;
            }

            $nodes[] = $copy;
        }

        return $nodes;
    }

    /**
     * Depth-first insert of `$label` under the first node labelled `$parentLabel`.
     *
     * @param list<array<string, mixed>> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private static function insertUnder(array $nodes, string $parentLabel, string $label): array
    {
        foreach ($nodes as $index => $node) {
            /** @var list<array<string, mixed>> $children */
            $children = is_array($node['children'] ?? null) ? array_values($node['children']) : [];

            if ((string) ($node['label'] ?? '') === $parentLabel) {
                $children[] = ['label' => $label];
                $node['children'] = $children;
                $nodes[$index] = $node;

                return $nodes;
            }

            if ([] !== $children) {
                $node['children'] = self::insertUnder($children, $parentLabel, $label);
                $nodes[$index] = $node;
            }
        }

        return $nodes;
    }

    private static function labelOf(mixed $item): string
    {
        if (is_string($item)) {
            $item = ['label' => $item];
        }

        if (!is_array($item) || !isset($item['label']) || !is_string($item['label'])) {
            throw CategorySublevelException::nodeWithoutLabel();
        }

        return Category::label($item['label']);
    }
}
