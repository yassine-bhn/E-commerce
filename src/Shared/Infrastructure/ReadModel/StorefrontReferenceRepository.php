<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\ReadModel;

use App\Shared\Domain\Storefront\DeliveryPolicy;
use Doctrine\DBAL\Connection;

final class StorefrontReferenceRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function deliveryPolicy(): DeliveryPolicy
    {
        $row = $this->connection->fetchAssociative(
            'SELECT delivery_fee_minor, free_delivery_threshold_minor FROM storefront_shipping_policy WHERE id = 1',
        );
        if (false === $row) {
            throw new \RuntimeException('Storefront shipping policy is not configured');
        }

        return new DeliveryPolicy((int) $row['delivery_fee_minor'], (int) $row['free_delivery_threshold_minor']);
    }

    public function promoBasisPoints(?string $code): ?int
    {
        if (null === $code || '' === trim($code)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT discount_basis_points FROM storefront_promo_code WHERE code = :code AND active = true',
            ['code' => strtoupper(trim($code))],
        );
        if (false === $row) {
            return null;
        }

        return (int) $row['discount_basis_points'];
    }

    public function isQuoteCategory(string $slug): bool
    {
        $eligible = $this->connection->fetchOne(
            'SELECT quote_eligible FROM storefront_category WHERE slug = :slug',
            ['slug' => $slug],
        );

        return filter_var($eligible, FILTER_VALIDATE_BOOLEAN);
    }

    /** @return list<string> */
    public function activePromoCodes(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['code'],
            $this->connection->fetchAllAssociative(
                'SELECT code FROM storefront_promo_code WHERE active = true ORDER BY code ASC',
            ),
        );
    }

    /**
     * The storefront navigation taxonomy (spec R3/R6): one entry per visible category, each
     * carrying its own `subcategories` JSON tree in the exact `slug`/`label`/`subs` shape the
     * live frontend already consumes.
     *
     * A hidden category is dropped together with its whole subtree — the nodes of its own
     * JSON tree go with it, and so does every legacy `parent_slug` descendant. Hiding is a
     * read-side concern only: the row and its products stay in place and stay reachable.
     *
     * @return list<array{slug: string, label: string, subs: list<array{label: string, children?: list<array<string, mixed>>}>}>
     */
    public function categories(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT slug, label, subcategories, hidden, parent_slug FROM storefront_category ORDER BY sort_order ASC, slug ASC',
        );

        $bySlug = [];
        foreach ($rows as $row) {
            $bySlug[(string) $row['slug']] = $row;
        }

        return array_values(array_map(
            static function (array $row): array {
                /** @var list<mixed> $decoded */
                $decoded = json_decode((string) $row['subcategories'], true, 512, JSON_THROW_ON_ERROR);

                return [
                    'slug' => (string) $row['slug'],
                    'label' => (string) $row['label'],
                    'subs' => self::normalizeSubs($decoded),
                ];
            },
            array_filter($rows, static fn (array $row): bool => !self::isObscured($row, $bySlug)),
        ));
    }

    /**
     * Is this row hidden itself, or a descendant of a hidden row?
     *
     * The ancestor walk is guarded so a malformed `parent_slug` cycle can never turn the
     * storefront read into an endless loop.
     *
     * @param array<string, mixed>                $row
     * @param array<string, array<string, mixed>> $rowsBySlug
     * @param array<string, bool>                 $visited
     */
    private static function isObscured(array $row, array $rowsBySlug, array $visited = []): bool
    {
        if (filter_var($row['hidden'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        $parent = isset($row['parent_slug']) ? trim((string) $row['parent_slug']) : '';
        if ('' === $parent || isset($visited[$parent]) || !isset($rowsBySlug[$parent])) {
            return false;
        }

        $visited[$parent] = true;

        return self::isObscured($rowsBySlug[$parent], $rowsBySlug, $visited);
    }

    /**
     * Normalizes the stored subcategory tree into the hierarchical shape expected
     * by the storefront: `list<array{label: string, children?: list<...>}>`.
     *
     * A plain string entry (legacy flat format) is converted to `{label: <string>}`.
     *
     * @param list<mixed> $subs
     *
     * @return list<array{label: string, children?: list<array<string, mixed>>}>
     */
    private static function normalizeSubs(array $subs): array
    {
        $nodes = [];
        foreach ($subs as $node) {
            if (is_string($node)) {
                $nodes[] = ['label' => $node];
                continue;
            }

            if (!is_array($node) || !isset($node['label'])) {
                continue;
            }

            $entry = ['label' => (string) $node['label']];
            if (isset($node['children']) && is_array($node['children'])) {
                $entry['children'] = self::normalizeSubs(array_values($node['children']));
            }

            $nodes[] = $entry;
        }

        return $nodes;
    }

    /** @return list<string> */
    public function brands(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['name'],
            $this->connection->fetchAllAssociative('SELECT name FROM storefront_brand ORDER BY name ASC'),
        );
    }

    /** @return list<string> */
    public function governorates(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['name'],
            $this->connection->fetchAllAssociative('SELECT name FROM storefront_governorate ORDER BY sort_order ASC'),
        );
    }
}
