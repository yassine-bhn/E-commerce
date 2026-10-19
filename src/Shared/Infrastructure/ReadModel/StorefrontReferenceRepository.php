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
     * @return list<array{slug: string, label: string, subs: list<array{label: string, children?: list<array<string, mixed>>}>}>
     */
    public function categories(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT slug, label, subcategories FROM storefront_category ORDER BY sort_order ASC',
        );

        return array_map(static function (array $row): array {
            /** @var list<mixed> $decoded */
            $decoded = json_decode((string) $row['subcategories'], true, 512, JSON_THROW_ON_ERROR);

            return [
                'slug' => (string) $row['slug'],
                'label' => (string) $row['label'],
                'subs' => self::normalizeSubs($decoded),
            ];
        }, $rows);
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
