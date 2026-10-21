<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service;

/**
 * Pure helpers for the storefront subcategory tree stored as JSON in
 * `storefront_category.subcategories`: `[{label: string, children?: [...]}]`.
 */
final class SubcategoryTree
{
    private const int MAX_DEPTH = 4;
    private const int MAX_LABEL_LENGTH = 128;

    /**
     * Validates and normalizes an untrusted tree coming from the admin UI.
     * Returns null when the tree is malformed.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function normalize(mixed $raw, int $depth = 1): ?array
    {
        if (!is_array($raw) || $depth > self::MAX_DEPTH) {
            return null;
        }

        $nodes = [];
        foreach ($raw as $item) {
            if (!is_array($item) || !isset($item['label']) || !is_string($item['label'])) {
                return null;
            }

            $label = trim($item['label']);
            if ('' === $label || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
                return null;
            }

            $node = ['label' => $label];

            $children = $item['children'] ?? [];
            if (!is_array($children)) {
                return null;
            }
            if ([] !== $children) {
                $normalizedChildren = self::normalize($children, $depth + 1);
                if (null === $normalizedChildren) {
                    return null;
                }
                $node['children'] = $normalizedChildren;
            }

            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * Flattens a stored tree (nodes or legacy plain strings) into every label.
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
}
