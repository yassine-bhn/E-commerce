<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Storefront reference data tables (shipping, promos, categories, brands, governorates) + DB seed';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE storefront_shipping_policy (
            id SMALLINT NOT NULL,
            delivery_fee_minor INT NOT NULL,
            free_delivery_threshold_minor INT NOT NULL,
            PRIMARY KEY(id),
            CONSTRAINT chk_single_shipping_policy CHECK (id = 1)
        )');

        $this->addSql('CREATE TABLE storefront_promo_code (
            code VARCHAR(32) NOT NULL,
            discount_basis_points INT NOT NULL,
            active BOOLEAN NOT NULL DEFAULT true,
            PRIMARY KEY(code)
        )');

        $this->addSql('CREATE TABLE storefront_category (
            slug VARCHAR(64) NOT NULL,
            label VARCHAR(128) NOT NULL,
            subcategories JSON NOT NULL,
            quote_eligible BOOLEAN NOT NULL DEFAULT false,
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY(slug)
        )');

        $this->addSql('CREATE TABLE storefront_brand (
            name VARCHAR(64) NOT NULL,
            PRIMARY KEY(name)
        )');

        $this->addSql('CREATE TABLE storefront_governorate (
            name VARCHAR(64) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY(name)
        )');

        $this->addSql('INSERT INTO storefront_shipping_policy (id, delivery_fee_minor, free_delivery_threshold_minor) VALUES (1, 15, 1000)');
        $this->addSql("INSERT INTO storefront_promo_code (code, discount_basis_points, active) VALUES ('ZIDEN10', 1000, true), ('BIENVENUE', 500, true)");

        $categories = [
            ['piscine', 'Piscine', ['Équipement technique', 'Accessoires et produits d\'entretien', 'Revêtement mosaïque'], true, 1],
            ['plomberie', 'Plomberie', ['Eau', 'Tubes', 'Raccords', 'Vannes', 'Évacuation', 'Outillage'], true, 2],
            ['robinetterie-spa-sanitaire', 'Robinetterie / Spa / Sanitaire', ['Mitigeurs', 'Thermostatiques', 'Spa', 'Lavabo', 'Vasque', 'Receveur douche', 'Baignoire', 'WC', 'Pack sanitaire', 'Accessoires'], true, 3],
            ['chauffage', 'Chauffage', ['Chauffage central', 'Chauffage mural', 'Chauffe-eau'], true, 4],
            ['promotions', 'Promotions', [], false, 5],
        ];
        foreach ($categories as [$slug, $label, $subs, $quote, $order]) {
            $this->addSql(
                'INSERT INTO storefront_category (slug, label, subcategories, quote_eligible, sort_order) VALUES (:slug, :label, :subs, :quote, :ord)',
                [
                    'slug' => $slug,
                    'label' => $label,
                    'subs' => json_encode($subs, JSON_THROW_ON_ERROR),
                    'quote' => $quote,
                    'ord' => $order,
                ],
                ['quote' => \Doctrine\DBAL\ParameterType::BOOLEAN],
            );
        }

        foreach (['Duravit', 'Geberit', 'Sanibel', 'Sopal'] as $brand) {
            $this->addSql('INSERT INTO storefront_brand (name) VALUES (:name)', ['name' => $brand]);
        }

        $governorates = [
            'Ariana', 'Béja', 'Ben Arous', 'Bizerte', 'Gabès', 'Gafsa', 'Jendouba', 'Kairouan', 'Kasserine', 'Kébili',
            'Le Kef', 'Mahdia', 'La Manouba', 'Médenine', 'Monastir', 'Nabeul', 'Sfax', 'Sidi Bouzid', 'Siliana', 'Sousse',
            'Tataouine', 'Tozeur', 'Tunis', 'Zaghouan',
        ];
        foreach ($governorates as $i => $name) {
            $this->addSql('INSERT INTO storefront_governorate (name, sort_order) VALUES (:name, :ord)', ['name' => $name, 'ord' => $i + 1]);
        }

        $fixturePath = \dirname(__DIR__).'/fixtures/zidane-storefront-catalog.json';
        if (!is_readable($fixturePath)) {
            return;
        }

        /** @var array{products: list<array<string, mixed>>} $data */
        $data = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
        foreach ($data['products'] as $p) {
            $id = self::uuidFromSlug((string) $p['slug']);
            $variants = $p['variants'] ?? [];
            $this->addSql(
                'INSERT INTO storefront_product_view (
                    id, slug, name, brand, ref, category_slug, subcategory, price_minor, old_price_minor, currency,
                    stock, material, dims, image_path, rating, reviews, is_new, is_best, headrest, description, variants
                ) VALUES (
                    :id, :slug, :name, :brand, :ref, :category, :sub, :price, :old_price, :currency,
                    :stock, :material, :dims, :image, :rating, :reviews, :is_new, :is_best, :headrest, :description, :variants
                ) ON CONFLICT (slug) DO NOTHING',
                [
                    'id' => $id,
                    'slug' => $p['slug'],
                    'name' => $p['name'],
                    'brand' => $p['brand'],
                    'ref' => $p['ref'],
                    'category' => $p['category'],
                    'sub' => $p['sub'],
                    'price' => $p['price'],
                    'old_price' => $p['oldPrice'] ?? null,
                    'currency' => 'TND',
                    'stock' => $p['stock'],
                    'material' => $p['material'],
                    'dims' => $p['dims'],
                    'image' => $p['imagePath'],
                    'rating' => $p['rating'],
                    'reviews' => $p['reviews'],
                    'is_new' => !empty($p['isNew']) ? 'true' : 'false',
                    'is_best' => !empty($p['best']) ? 'true' : 'false',
                    'headrest' => !empty($p['headrest']) ? 'true' : 'false',
                    'description' => $p['description'],
                    'variants' => json_encode($variants, JSON_THROW_ON_ERROR),
                ],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE storefront_governorate');
        $this->addSql('DROP TABLE storefront_brand');
        $this->addSql('DROP TABLE storefront_category');
        $this->addSql('DROP TABLE storefront_promo_code');
        $this->addSql('DROP TABLE storefront_shipping_policy');
    }

    private static function uuidFromSlug(string $slug): string
    {
        $hash = md5('zidane-storefront:'.$slug);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            '4'.substr($hash, 13, 3),
            dechex((hexdec(substr($hash, 16, 4)) & 0x3FFF) | 0x8000),
            substr($hash, 20, 12),
        );
    }
}
