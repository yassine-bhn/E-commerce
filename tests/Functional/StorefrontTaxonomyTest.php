<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StorefrontTaxonomyTest extends WebTestCase
{
    private const array EXPECTED_SLUGS = [
        'sanitaires',
        'robinetterie',
        'chauffage',
        'plomberie',
        'chauffage-par-le-sol',
        'drainage',
        'traitement-des-eaux',
        'pompe',
        'piscine',
        'electricite',
        'promotions',
    ];

    public function testReferenceExposesFrontendTaxonomy(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/storefront/reference');

        self::assertResponseIsSuccessful();
        /** @var array{categories: list<array{slug: string, label: string, subs: list<array{label: string, children?: list<array<string, mixed>>}>}>} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        $bySlug = [];
        foreach ($data['categories'] as $category) {
            $bySlug[$category['slug']] = $category;
        }

        foreach (self::EXPECTED_SLUGS as $slug) {
            self::assertArrayHasKey($slug, $bySlug, sprintf('Missing storefront category "%s"', $slug));
        }

        // Promotions must have no subs.
        self::assertSame([], $bySlug['promotions']['subs']);

        // Sanitaires must expose a top-level "Baignoire" node.
        $sanitairesLabels = array_map(
            static fn (array $node): string => $node['label'],
            $bySlug['sanitaires']['subs'],
        );
        self::assertContains('Baignoire', $sanitairesLabels);

        // Hierarchical nodes must preserve their children (e.g. "WC").
        $wc = null;
        foreach ($bySlug['sanitaires']['subs'] as $node) {
            if ('WC' === $node['label']) {
                $wc = $node;
            }
        }
        self::assertIsArray($wc);
        self::assertArrayHasKey('children', $wc);
        $children = $wc['children'] ?? [];
        self::assertContains('Cuvette suspendu', array_column($children, 'label'));
    }

    public function testProductsFilteredBySanitairesBaignoire(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/storefront/products?category=sanitaires&sub=Baignoire');

        self::assertResponseIsSuccessful();
        /** @var array{items: list<array<string, mixed>>} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertNotEmpty($data['items']);
        $slugs = array_map(static fn (array $product): string => (string) $product['slug'], $data['items']);
        self::assertContains('baignoire-d-code', $slugs);
    }
}
