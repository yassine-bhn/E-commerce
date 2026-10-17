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

    /** @return list<array{slug: string, label: string, subs: list<string>}> */
    public function categories(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT slug, label, subcategories FROM storefront_category ORDER BY sort_order ASC',
        );

        return array_map(static function (array $row): array {
            /** @var list<string> $subs */
            $subs = json_decode((string) $row['subcategories'], true, 512, JSON_THROW_ON_ERROR);

            return [
                'slug' => (string) $row['slug'],
                'label' => (string) $row['label'],
                'subs' => $subs,
            ];
        }, $rows);
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
