<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align storefront category taxonomy and product category/subcategory with the frontend catalog';
    }

    public function up(Schema $schema): void
    {
        $this->realignCategories($this->frontendTaxonomy());
        $this->realignProducts(self::UP_PRODUCT_MAPPING);
    }

    public function down(Schema $schema): void
    {
        $this->realignCategories($this->legacyTaxonomy());
        $this->realignProducts(self::DOWN_PRODUCT_MAPPING);
    }

    /**
     * @param list<array{0: string, 1: string, 2: list<array<string, mixed>>, 3: bool}> $categories
     */
    private function realignCategories(array $categories): void
    {
        $this->addSql('DELETE FROM storefront_category');

        foreach ($categories as $sortOrder => [$slug, $label, $subs, $quoteEligible]) {
            $this->addSql(
                'INSERT INTO storefront_category (slug, label, subcategories, quote_eligible, sort_order, parent_slug)
                 VALUES (:slug, :label, :subs, :quote, :ord, NULL)',
                [
                    'slug' => $slug,
                    'label' => $label,
                    'subs' => json_encode($subs, JSON_THROW_ON_ERROR),
                    'quote' => $quoteEligible,
                    'ord' => $sortOrder,
                ],
                ['quote' => ParameterType::BOOLEAN],
            );
        }
    }

    /**
     * @param array<string, array{0: string, 1: string}> $mapping
     */
    private function realignProducts(array $mapping): void
    {
        foreach ($mapping as $slug => [$category, $sub]) {
            $this->addSql(
                'UPDATE storefront_product_view SET category_slug = :category, subcategory = :sub WHERE slug = :slug',
                [
                    'category' => $category,
                    'sub' => $sub,
                    'slug' => $slug,
                ],
            );
        }
    }

    /**
     * Taxonomy mirroring `Zidane_front_web/src/data/catalog.ts` (`categories` + `promotionCategory`).
     *
     * @return list<array{0: string, 1: string, 2: list<array<string, mixed>>, 3: bool}>
     */
    private function frontendTaxonomy(): array
    {
        /** @var callable(string, list<array<string, mixed>>=): array<string, mixed> $node */
        $node = static function (string $label, array $children = []): array {
            return [] === $children ? ['label' => $label] : ['label' => $label, 'children' => $children];
        };

        /** @var callable(list<string>): list<array<string, mixed>> $leaves */
        $leaves = static fn (array $labels): array => array_map(
            static fn (string $label): array => ['label' => $label],
            $labels,
        );

        return [
            ['robinetterie', 'Robinetterie', [
                $node('Robinetterie Artistique', [$node('Robinetterie temporisé')]),
                $node('Accessoires Robinetterie', $leaves(['Série Djerba', 'Série Zembra', 'Série Tozeur', 'Série Sousse', 'Série Nabeul', 'Série Monastir', 'Série Salakta', 'Série Tabarka'])),
                $node('DEANTE', $leaves(['Como', 'Siena', 'Série A.1', 'Série D-Neo', 'Série No.1', 'Série B.1', 'Série B.2', 'Série Tulum', 'Accessoires Robinetterie duravit'])),
                $node('PALAZZANI'),
                $node('SOPAL'),
                $node('HANSGROHE'),
                $node('DURAVIT'),
                $node('MISTILLO'),
                $node('VENIZIA', [$node('Mitigeurs thermostatique'), $node('Toilette')]),
            ], true],
            ['sanitaires', 'Sanitaires', [
                $node('Lavabo', $leaves(['Vasque', 'Lavabo', 'Lavabo double', 'Lave mains'])),
                $node('Receveur douche', $leaves(['Sanitaire Céramique', 'Sanitaire inox'])),
                $node('Cabine et paroi douche'),
                $node('Baignoire'),
                $node('Bidet'),
                $node('WC', $leaves(['Cuvette suspendu', 'Cuvette a poser', 'Urinoir', 'Abattant', 'PMR'])),
                $node('Pack Sanitaire'),
                $node('Meuble et miroir salle de bain', $leaves(['Meuble salle de bain', 'Miroir salle de bain', 'Accessoires salle de bain'])),
                $node('Mecanisme de chasse', $leaves(['GEBERIT', 'DURAVIT', 'EJIM'])),
            ], true],
            ['chauffage', 'Chauffage', [
                $node('Chauffe bain à gaz', $leaves(['Chaudière électrique', 'Chaudière sur socl', 'Chauffage mural à gaz'])),
                $node('Chauffe eau électrique'),
                $node('Chaudière'),
                $node('Radiateur et sèches serviette', $leaves(['Radiateur', 'Séche serviette', 'Accessoires radiateur et sèches serviette'])),
                $node('Accumulateur'),
                $node('Thermostat', $leaves(['Manuel', 'Programmable', 'Sans fil'])),
                $node('Chauffage extérieur'),
            ], true],
            ['chauffage-par-le-sol', 'Chauffage par le sol', [], true],
            ['drainage', 'Drainage', [
                $node('Accessoires drainage'),
                $node('Caniveaux et siphon de douche', $leaves(['Caniveaux de douche', 'Siphon intérieur'])),
                $node('Caniveau et siphon extérieur', $leaves(['Siphon extérieur', 'Caniveaux'])),
            ], true],
            ['plomberie', 'Plomberie', [
                $node('Tube PEHD eau potable', $leaves(['Bouchon', 'Mamelon', 'Manchon', 'Raccord rex', 'Divers', 'Coude', 'Te', 'Réduction'])),
                $node('Tube insonorisé', [$node('TUBE', [$node('Accessoire')])]),
                $node('Pvc et Multicouche', $leaves(['Pvc', 'Multicouche'])),
                $node('Cuivre'),
                $node('Tube PEHD évacuation', $leaves(['Tube', 'Accessoire', 'Reduction'])),
                $node('Collecteur et vanne'),
                $node('Accessoires laiton'),
                $node('Tube gorge'),
            ], true],
            ['traitement-des-eaux', 'Traitement des eaux', [
                $node("Fontaine d'eau fraiche", $leaves(['Avec pompe', 'Sans pompe'])),
                $node('Osmoseur'),
                $node('Adoucisseur', [$node('Accessoire')]),
            ], true],
            ['pompe', 'Pompe', [$node('Broyeur')], true],
            ['piscine', 'Piscine', $leaves(['Nettoyage', 'Filtration', "Produits d'entretien piscine"]), true],
            ['electricite', 'Electricité', $leaves(['Eco led', 'Supportage']), true],
            ['promotions', 'Promotions', [], false],
        ];
    }

    /**
     * Previous taxonomy, restored by {@see self::down()}.
     *
     * @return list<array{0: string, 1: string, 2: list<array<string, mixed>>, 3: bool}>
     */
    private function legacyTaxonomy(): array
    {
        return [
            ['piscine', 'Piscine', ['Équipement technique', "Accessoires et produits d'entretien", 'Revêtement mosaïque'], true],
            ['plomberie', 'Plomberie', ['Eau', 'Tubes', 'Raccords', 'Vannes', 'Évacuation', 'Outillage'], true],
            ['robinetterie-spa-sanitaire', 'Robinetterie / Spa / Sanitaire', ['Mitigeurs', 'Thermostatiques', 'Spa', 'Lavabo', 'Vasque', 'Receveur douche', 'Baignoire', 'WC', 'Pack sanitaire', 'Accessoires'], true],
            ['chauffage', 'Chauffage', ['Chauffage central', 'Chauffage mural', 'Chauffe-eau'], true],
            ['promotions', 'Promotions', [], false],
        ];
    }

    /** @var array<string, array{0: string, 1: string}> */
    private const array UP_PRODUCT_MAPPING = [
        'baignoire-d-code' => ['sanitaires', 'Baignoire'],
        'baignoire-sabot' => ['sanitaires', 'Baignoire'],
        'baignoire-starck-ilot' => ['sanitaires', 'Baignoire'],
        'baignoire-sopal-classic' => ['sanitaires', 'Baignoire'],
        'lavabo-me-by-starck' => ['sanitaires', 'Lavabo'],
        'vasque-poser-sanibel' => ['sanitaires', 'Vasque'],
        'wc-suspendu-rimless' => ['sanitaires', 'WC'],
        'pack-wc-sopal' => ['sanitaires', 'Pack Sanitaire'],
        'receveur-extra-plat' => ['sanitaires', 'Receveur douche'],
        'mitigeur-lavabo-chrome' => ['robinetterie', 'SOPAL'],
        'mitigeur-thermostatique' => ['robinetterie', 'Mitigeurs thermostatique'],
        'radiateur-acier-t22' => ['chauffage', 'Radiateur'],
        'kit-outillage-pro' => ['plomberie', 'Divers'],
    ];

    /** @var array<string, array{0: string, 1: string}> */
    private const array DOWN_PRODUCT_MAPPING = [
        'baignoire-d-code' => ['robinetterie-spa-sanitaire', 'Baignoire'],
        'baignoire-sabot' => ['robinetterie-spa-sanitaire', 'Baignoire'],
        'baignoire-starck-ilot' => ['robinetterie-spa-sanitaire', 'Baignoire'],
        'baignoire-sopal-classic' => ['robinetterie-spa-sanitaire', 'Baignoire'],
        'lavabo-me-by-starck' => ['robinetterie-spa-sanitaire', 'Lavabo'],
        'vasque-poser-sanibel' => ['robinetterie-spa-sanitaire', 'Vasque'],
        'wc-suspendu-rimless' => ['robinetterie-spa-sanitaire', 'WC'],
        'pack-wc-sopal' => ['robinetterie-spa-sanitaire', 'Pack sanitaire'],
        'receveur-extra-plat' => ['robinetterie-spa-sanitaire', 'Receveur douche'],
        'mitigeur-lavabo-chrome' => ['robinetterie-spa-sanitaire', 'Mitigeurs'],
        'mitigeur-thermostatique' => ['robinetterie-spa-sanitaire', 'Thermostatiques'],
        'radiateur-acier-t22' => ['chauffage', 'Chauffage central'],
        'kit-outillage-pro' => ['plomberie', 'Outillage'],
    ];
}
