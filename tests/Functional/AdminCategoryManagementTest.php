<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * WP2 — the admin dashboard category surface (spec R1-R8, R24) plus the hidden-aware
 * storefront navigation read (R6).
 *
 * Every write goes through a command on `command.bus` and every read through the authorized
 * DBAL finder, so this suite proves the routes the dashboard calls, the JSON tree
 * representation locked by ADR-0006, and the two halves of the hide contract at once.
 */
final class AdminCategoryManagementTest extends WebTestCase
{
    /** @var list<string> */
    private array $categorySlugs = [];
    /** @var list<string> */
    private array $productSlugs = [];
    /** @var list<string> */
    private array $userIds = [];

    protected function tearDown(): void
    {
        if ([] !== $this->productSlugs) {
            $this->db()->executeStatement(
                'DELETE FROM storefront_product_view WHERE slug IN (:v)',
                ['v' => $this->productSlugs],
                ['v' => ArrayParameterType::STRING],
            );
        }
        if ([] !== $this->categorySlugs) {
            $this->db()->executeStatement(
                'DELETE FROM storefront_category WHERE slug IN (:v)',
                ['v' => $this->categorySlugs],
                ['v' => ArrayParameterType::STRING],
            );
        }
        if ([] !== $this->userIds) {
            $this->db()->executeStatement(
                'DELETE FROM customer_profile WHERE id IN (:v)',
                ['v' => $this->userIds],
                ['v' => ArrayParameterType::STRING],
            );
        }

        parent::tearDown();
    }

    public function testAnAdminCreatesATopCategoryThatBothReadsReturn(): void
    {
        $client = $this->loginAs('ROLE_SUPER_ADMIN');
        $slug = $this->uniqueSlug('cat');

        $client->jsonRequest('POST', '/api/v1/admin/catalog/categories', [
            'slug' => $slug,
            'label' => 'Robinetterie de test',
            'quoteEligible' => false,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $created = $this->json($client);
        self::assertSame($slug, $created['slug']);
        self::assertSame('Robinetterie de test', $created['label']);
        self::assertFalse($created['hidden'], 'a new category is visible (R5 default)');
        self::assertSame([], $created['subcategories']);
        self::assertSame(0, $created['productCount']);

        // The slug is optional: it is derived from the label like every other admin surface.
        $client->jsonRequest('POST', '/api/v1/admin/catalog/categories', ['label' => 'Carrelage 60x60']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $derived = (string) $this->json($client)['slug'];
        self::assertSame('carrelage-60x60', $derived);
        $this->categorySlugs[] = $derived;

        // R1: the admin list sees it at once; the storefront only after the Super Admin approves
        // it (spec-run-8 R28 — approval and publication are separate from creation).
        self::assertContains($slug, $this->adminListSlugs($client));
        self::assertNotContains($slug, $this->navigationSlugs($client), 'a pending submission is not navigable');

        $this->approve($client, 'category', $slug);
        self::assertContains($slug, $this->navigationSlugs($client));
    }

    public function testAnInvalidLabelOrATakenSlugIsRefusedWithoutWritingARow(): void
    {
        $client = $this->loginAs('ROLE_SUPER_ADMIN');
        $slug = $this->uniqueSlug('cat');
        $this->categorySlugs[] = $slug;

        $client->jsonRequest('POST', '/api/v1/admin/catalog/categories', ['slug' => $slug, 'label' => 'Première']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $client->jsonRequest('POST', '/api/v1/admin/catalog/categories', ['slug' => $slug, 'label' => 'Seconde']);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        $client->jsonRequest('POST', '/api/v1/admin/catalog/categories', ['slug' => $slug.'-blank', 'label' => '   ']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $client->jsonRequest('POST', '/api/v1/admin/catalog/categories', ['slug' => 'Mauvais Slug', 'label' => 'Slug invalide']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        self::assertSame(
            1,
            (int) $this->db()->fetchOne('SELECT COUNT(*) FROM storefront_category WHERE slug LIKE :p', ['p' => $slug.'%']),
            'a refused create writes nothing',
        );
    }

    public function testASubCategoryIsStoredAsANodeOfTheParentRowTree(): void
    {
        $client = $this->loginAs('ROLE_SUPER_ADMIN');
        $slug = $this->category($client, 'Chauffage de test');

        $client->jsonRequest('POST', '/api/v1/admin/catalog/categories/'.$slug.'/subcategories', ['label' => '  Radiateurs  ']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame([['label' => 'Radiateurs']], $this->json($client)['subcategories']);

        $client->jsonRequest('POST', '/api/v1/admin/catalog/categories/'.$slug.'/subcategories', [
            'label' => 'Radiateur à eau',
            'parentLabel' => 'Radiateurs',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(
            [['label' => 'Radiateurs', 'children' => [['label' => 'Radiateur à eau']]]],
            $this->json($client)['subcategories'],
        );

        // Q1: no child row was created, the node lives in the parent row's JSON tree.
        self::assertSame(
            0,
            (int) $this->db()->fetchOne('SELECT COUNT(*) FROM storefront_category WHERE parent_slug = :s', ['s' => $slug]),
        );
        $this->approve($client, 'category', $slug);
        self::assertSame([$slug], $this->navigationSlugs($client, [$slug]));
    }

    public function testAnUnknownParentIsNotFoundAndADuplicateSiblingChangesNothing(): void
    {
        $client = $this->loginAs('ROLE_SUPER_ADMIN');
        $slug = $this->category($client, 'Plomberie de test', [['label' => 'Tubes']]);
        $before = $this->categoryPayload($client, $slug);

        $client->jsonRequest('POST', '/api/v1/admin/catalog/categories/inexistant-'.$slug.'/subcategories', ['label' => 'Alpha']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $client->jsonRequest('POST', '/api/v1/admin/catalog/categories/'.$slug.'/subcategories', ['label' => 'Tubes']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $client->jsonRequest('POST', '/api/v1/admin/catalog/categories/'.$slug.'/subcategories', ['label' => 'Alpha', 'parentLabel' => 'Inconnu']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        self::assertSame($before, $this->categoryPayload($client, $slug), 'the parent is untouched by a refused sub-level');
    }

    public function testSubLevelsObeyTheStoredTreeLimits(): void
    {
        $client = $this->loginAs('ROLE_SUPER_ADMIN');
        $slug = $this->category($client, 'Profondeur de test', [
            ['label' => 'N1', 'children' => [['label' => 'N2', 'children' => [['label' => 'N3', 'children' => [['label' => 'N4']]]]]]],
        ]);

        $client->jsonRequest('POST', '/api/v1/admin/catalog/categories/'.$slug.'/subcategories', ['label' => 'N5', 'parentLabel' => 'N4']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $client->jsonRequest('POST', '/api/v1/admin/catalog/categories/'.$slug.'/subcategories', ['label' => str_repeat('L', 129)]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $client->jsonRequest('POST', '/api/v1/admin/catalog/categories', [
            'slug' => $this->uniqueSlug('cat'),
            'label' => str_repeat('A', 129),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        self::assertSame(4, $this->categoryPayload($client, $slug)['subLevelCount'], 'depth stays capped at four levels');
    }

    public function testHidingAndUnHidingOnlyPersistTheVisibilityFlag(): void
    {
        $client = $this->loginAs('ROLE_SUPER_ADMIN');
        $slug = $this->category($client, 'Piscine de test', [['label' => 'Filtration']]);
        $productSlug = $this->product($client, $slug, 'Filtration');
        $before = $this->db()->fetchAssociative('SELECT * FROM storefront_category WHERE slug = :s', ['s' => $slug]);
        self::assertIsArray($before);
        $visiblePayload = $this->categoryPayload($client, $slug);

        $payload = ['hidden' => true];
        $client->jsonRequest('PATCH', '/api/v1/admin/catalog/categories/'.$slug.'/visibility', $payload);
        self::assertResponseIsSuccessful();
        $answer = $this->json($client);
        self::assertTrue($answer['hidden']);

        // The answer is the whole category, with only its visibility flag changed.
        $expected = $visiblePayload;
        $expected['hidden'] = true;
        self::assertSame($expected, $answer, 'the visibility answer carries the rest of the category unchanged');

        // Idempotent: hiding a hidden category is not an error and changes nothing else.
        $client->jsonRequest('PATCH', '/api/v1/admin/catalog/categories/'.$slug.'/visibility', ['hidden' => true]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json($client)['hidden']);

        $after = $this->db()->fetchAssociative('SELECT * FROM storefront_category WHERE slug = :s', ['s' => $slug]);
        self::assertIsArray($after);
        self::assertSame($before['label'], $after['label']);
        self::assertSame($before['subcategories'], $after['subcategories'], 'the JSON sub-levels survive a hide');
        self::assertSame($before['quote_eligible'], $after['quote_eligible']);
        self::assertSame($before['sort_order'], $after['sort_order']);
        self::assertTrue($after['hidden']);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM storefront_category WHERE slug = :s', ['s' => $slug]), 'R5: hiding is not a delete');
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM storefront_product_view WHERE slug = :s', ['s' => $productSlug]));

        // Refused payloads change nothing.
        $client->jsonRequest('PATCH', '/api/v1/admin/catalog/categories/'.$slug.'/visibility', ['hidden' => 'oui']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $client->jsonRequest('PATCH', '/api/v1/admin/catalog/categories/'.$slug.'/visibility', []);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $client->jsonRequest('PATCH', '/api/v1/admin/catalog/categories/'.$slug.'/visibility', ['hidden' => false]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->json($client)['hidden']);

        $client->jsonRequest('PATCH', '/api/v1/admin/catalog/categories/inexistant-'.$slug.'/visibility', ['hidden' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testHidingACategoryDropsItAndItsProductsFromTheStorefrontButKeepsThemInTheAdmin(): void
    {
        $client = $this->loginAs('ROLE_SUPER_ADMIN');
        $slug = $this->category($client, 'Assainissement de test', [['label' => 'Regard'], ['label' => 'Tuyau', 'children' => [['label' => 'PEHD']]]]);
        $this->approve($client, 'category', $slug);
        $childSlug = $slug.'-legacy';
        $this->categorySlugs[] = $childSlug;
        $this->db()->insert('storefront_category', [
            'slug' => $childSlug,
            'label' => 'Descendant hérité',
            'subcategories' => '[]',
            'quote_eligible' => false,
            'hidden' => false,
            'sort_order' => 500,
            'parent_slug' => $slug,
            'approval_status' => 'APPROVED',
        ], ['quote_eligible' => ParameterType::BOOLEAN, 'hidden' => ParameterType::BOOLEAN]);
        $productSlug = $this->product($client, $slug, 'Regard');
        $this->approve($client, 'product', $productSlug);
        $this->publish($client, $productSlug);

        $navigation = $this->navigation($client);
        self::assertContains($slug, array_column($navigation, 'slug'));
        self::assertContains($childSlug, array_column($navigation, 'slug'));

        $client->request('GET', '/api/v1/storefront/products/'.$productSlug);
        self::assertResponseIsSuccessful('an approved, published product of a visible category is reachable');

        $client->jsonRequest('PATCH', '/api/v1/admin/catalog/categories/'.$slug.'/visibility', ['hidden' => true]);
        self::assertResponseIsSuccessful();

        $slugs = array_column($this->navigation($client), 'slug');
        self::assertNotContains($slug, $slugs, 'R6: the hidden category is gone from the navigation');
        self::assertNotContains($childSlug, $slugs, 'R6: and so is its whole subtree');

        // spec-run-8 R31 — category visibility now governs its products too: they are no longer
        // publicly reachable, while the rows and the admin reads keep them. This narrows the
        // behaviour this test used to lock, deliberately (spec-run-8 Decision, §4 vs §7).
        $client->request('GET', '/api/v1/storefront/products/'.$productSlug);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a product of a hidden category is not publicly reachable');

        $client->request('GET', '/api/v1/storefront/products?category='.$slug);
        self::assertResponseIsSuccessful();
        self::assertNotContains($productSlug, array_column($this->json($client)['items'] ?? [], 'slug'));

        // The row and its admin reads are untouched by hiding.
        self::assertSame(
            1,
            (int) $this->db()->fetchOne('SELECT COUNT(*) FROM storefront_product_view WHERE slug = :s', ['s' => $productSlug]),
            'hiding never deletes a product',
        );
    }

    public function testThePublicNavigationPayloadKeepsItsContract(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/storefront/reference');
        self::assertResponseIsSuccessful();
        $reference = $this->json($client);

        foreach ($reference['categories'] ?? [] as $entry) {
            self::assertSame(['slug', 'label', 'subs'], array_keys($entry), 'slug/label/subs stays byte-compatible (R3)');
            self::assertIsString($entry['slug']);
            self::assertIsString($entry['label']);
            self::assertIsArray($entry['subs']);
            foreach ($entry['subs'] as $node) {
                self::assertSame('string', get_debug_type($node['label'] ?? null));
                if (array_key_exists('children', $node)) {
                    self::assertSame(['label', 'children'], array_keys($node));
                } else {
                    self::assertSame(['label'], array_keys($node));
                }
            }
        }
    }

    public function testTheAdminListReturnsEveryCategoryIncludingHiddenOnesInAStableOrder(): void
    {
        $client = $this->loginAs('ROLE_SUPER_ADMIN');
        $first = $this->category($client, 'Liste un', [['label' => 'Alpha']]);
        $second = $this->category($client, 'Liste deux');
        $client->jsonRequest('PATCH', '/api/v1/admin/catalog/categories/'.$second.'/visibility', ['hidden' => true]);
        self::assertResponseIsSuccessful();
        $productSlug = $this->product($client, $first, 'Alpha');

        $items = $this->adminList($client);
        $slugs = array_column($items, 'slug');
        self::assertContains($first, $slugs, 'R8: hidden rows stay in the admin list');
        self::assertContains($second, $slugs);

        foreach ($items as $item) {
            self::assertSame(
                ['slug', 'label', 'parentSlug', 'subcategories', 'subLevelCount', 'hidden', 'quoteEligible', 'productCount', 'sortOrder',
                    'approvalStatus', 'submittedBy', 'rejectionReason'],
                array_keys($item),
            );
            self::assertIsBool($item['hidden']);
            self::assertIsInt($item['productCount']);
            self::assertIsInt($item['subLevelCount']);
        }

        $bySlug = [];
        foreach ($items as $item) {
            $bySlug[(string) $item['slug']] = $item;
        }
        self::assertTrue($bySlug[$second]['hidden']);
        self::assertFalse($bySlug[$first]['hidden']);
        self::assertSame([['label' => 'Alpha']], $bySlug[$first]['subcategories']);
        self::assertSame(1, $bySlug[$first]['productCount'], in_array($productSlug, array_column($items, 'slug'), true) ? '' : 'product count is per category');

        // Stable order: sort_order then slug, and identical across two calls.
        $order = array_map(static fn (array $item): array => [$item['sortOrder'], $item['slug']], $items);
        $sorted = $order;
        usort($sorted, static fn (array $a, array $b): int => $a[0] === $b[0] ? strcmp((string) $a[1], (string) $b[1]) : $a[0] <=> $b[0]);
        self::assertSame($sorted, $order);
        self::assertSame($slugs, array_column($this->adminList($client), 'slug'));
    }

    public function testDeletingRefusesANonEmptyCategoryWithItsCountsAndAnswersNotFoundAfterwards(): void
    {
        $client = $this->loginAs('ROLE_SUPER_ADMIN');
        $slug = $this->category($client, 'À supprimer', [['label' => 'Alpha', 'children' => [['label' => 'Sous alpha']]], ['label' => 'Beta']]);
        $productSlug = $this->product($client, $slug, 'Alpha');

        $client->request('DELETE', '/api/v1/admin/catalog/categories/'.$slug);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $refused = $this->json($client);
        self::assertSame('Category Not Empty', $refused['error']);
        self::assertStringContainsString('3 subcategories', (string) $refused['message'], 'the JSON nodes are counted (Q1)');
        self::assertStringContainsString('1 products', (string) $refused['message']);
        self::assertNotNull($this->categoryPayloadOrNull($client, $slug), 'nothing was written');

        // The last product alone does not empty the category: its sub-level nodes still count.
        $client->request('DELETE', '/api/v1/admin/catalog/products/'.$productSlug);
        self::assertResponseIsSuccessful();
        $client->request('DELETE', '/api/v1/admin/catalog/categories/'.$slug);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        // Emptying the tree is the only way to reach an empty, deletable category.
        $client->jsonRequest('PUT', '/api/v1/admin/catalog/categories/'.$slug, ['label' => 'À supprimer', 'subcategories' => []]);
        self::assertResponseIsSuccessful();
        $client->request('DELETE', '/api/v1/admin/catalog/categories/'.$slug);
        self::assertResponseIsSuccessful();
        self::assertNull($this->categoryPayloadOrNull($client, $slug));

        // A second delete is a not-found, never a 500.
        $client->request('DELETE', '/api/v1/admin/catalog/categories/'.$slug);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testHidingAndDeletingNeverTouchTheOrderSnapshots(): void
    {
        $client = $this->loginAs('ROLE_SUPER_ADMIN');
        $slug = $this->category($client, 'Catégorie historique');
        $ordersBefore = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM ordering_orders');
        $linesBefore = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM ordering_order_lines');

        $client->jsonRequest('PATCH', '/api/v1/admin/catalog/categories/'.$slug.'/visibility', ['hidden' => true]);
        self::assertResponseIsSuccessful();
        $client->request('DELETE', '/api/v1/admin/catalog/categories/'.$slug);
        self::assertResponseIsSuccessful();

        self::assertSame($ordersBefore, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM ordering_orders'), 'ECO-006');
        self::assertSame($linesBefore, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM ordering_order_lines'), 'ECO-006');
    }

    public function testGuestsGet401AndPlainCustomersGet403OnEveryAdminCategoryRoute(): void
    {
        $client = static::createClient();
        $slug = $this->uniqueSlug('cat');

        $adminRoutes = [
            ['GET', '/api/v1/admin/catalog/categories', null],
            ['POST', '/api/v1/admin/catalog/categories', ['label' => 'Interdit']],
            ['POST', '/api/v1/admin/catalog/categories/'.$slug.'/subcategories', ['label' => 'Interdit']],
            ['PUT', '/api/v1/admin/catalog/categories/'.$slug, ['label' => 'Interdit']],
            ['PATCH', '/api/v1/admin/catalog/categories/'.$slug.'/visibility', ['hidden' => true]],
            ['DELETE', '/api/v1/admin/catalog/categories/'.$slug, null],
        ];

        foreach ($adminRoutes as [$method, $path, $payload]) {
            if (null === $payload) {
                $client->request($method, $path);
            } else {
                $client->jsonRequest($method, $path, $payload);
            }
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, $method.' '.$path.' must answer 401 to a guest');
        }

        $client->loginUser($this->createUser('ROLE_USER'));
        foreach ($adminRoutes as [$method, $path, $payload]) {
            if (null === $payload) {
                $client->request($method, $path);
            } else {
                $client->jsonRequest($method, $path, $payload);
            }
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, $method.' '.$path.' must answer 403 to a plain customer');
        }

        // R24 corollary: nothing was written by the refused attempts.
        self::assertSame(
            0,
            (int) $this->db()->fetchOne(
                'SELECT COUNT(*) FROM storefront_category WHERE slug IN (:s)',
                ['s' => [$slug, 'interdit']],
                ['s' => ArrayParameterType::STRING],
            ),
        );
    }

    /**
     * @param array<array-key, mixed> $subcategories
     */
    private function category(KernelBrowser $client, string $label, array $subcategories = []): string
    {
        $slug = $this->uniqueSlug('cat');
        $client->jsonRequest('POST', '/api/v1/admin/catalog/categories', [
            'slug' => $slug,
            'label' => $label,
            'subcategories' => $subcategories,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, 'the test category '.$label.' must be created');
        self::assertSame($slug, $this->json($client)['slug']);

        return $slug;
    }

    private function product(KernelBrowser $client, string $categorySlug, string $sub): string
    {
        $slug = $this->uniqueSlug('prod');
        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', [
            'slug' => $slug,
            'name' => 'Produit de catégorie',
            'brand' => 'Sopal',
            'ref' => 'CAT-1',
            'category' => $categorySlug,
            'sub' => $sub,
            'price' => 300,
            'oldPrice' => null,
            'stock' => 'in',
            'material' => 'Acier',
            'dims' => '1 m',
            'image' => '/p-cat.jpg',
            'isNew' => false,
            'best' => false,
            'headrest' => false,
            'description' => 'Produit créé par un test.',
            'variants' => [],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->productSlugs[] = $slug;

        return $slug;
    }

    /**
     * spec-run-8 R22 — only the Super Admin approves, and every test that expects a submission to
     * reach the storefront has to take that step first.
     */
    private function approve(KernelBrowser $client, string $kind, string $slug): void
    {
        $collection = 'product' === $kind ? 'products' : 'categories';
        $client->jsonRequest('POST', '/api/v1/admin/catalog/submissions/'.$collection.'/'.$slug.'/approve');
        self::assertResponseIsSuccessful('approving the '.$kind.' '.$slug.' must succeed');
    }

    /** spec-run-8 R28/R29 — approval does not publish; a product joins the storefront only when published. */
    private function publish(KernelBrowser $client, string $slug): void
    {
        $client->jsonRequest('PATCH', '/api/v1/admin/catalog/products/'.$slug.'/publication', ['published' => true]);
        self::assertResponseIsSuccessful();
    }

    private function loginAs(string $role): KernelBrowser
    {
        $client = static::createClient();
        $client->loginUser($this->createUser($role));

        return $client;
    }

    private function createUser(string $role): CustomerUser
    {
        $id = Uuid::v7()->toRfc4122();
        $email = sprintf('admin.category.%s@example.com', substr($id, 0, 8));
        $this->db()->insert('customer_profile', [
            'id' => $id,
            'name' => 'Catégorie Test '.$role,
            'email' => $email,
            'password_hash' => null,
            'role' => $role,
            'status' => 'active',
        ]);
        $this->userIds[] = $id;

        return new CustomerUser($id, $email, null, $role, 'active');
    }

    /** @return list<array<string, mixed>> */
    private function adminList(KernelBrowser $client): array
    {
        $client->request('GET', '/api/v1/admin/catalog/categories');
        self::assertResponseIsSuccessful();

        /** @var list<array<string, mixed>> $items */
        $items = $this->json($client)['items'] ?? [];

        return $items;
    }

    /** @return list<string> */
    private function adminListSlugs(KernelBrowser $client): array
    {
        return array_column($this->adminList($client), 'slug');
    }

    /**
     * @param list<string>|null $only
     *
     * @return list<string>
     */
    private function navigationSlugs(KernelBrowser $client, ?array $only = null): array
    {
        $slugs = array_column($this->navigation($client), 'slug');
        if (null === $only) {
            return $slugs;
        }

        return array_values(array_filter($slugs, static fn (string $slug): bool => in_array($slug, $only, true)));
    }

    /** @return list<array<string, mixed>> */
    private function navigation(KernelBrowser $client): array
    {
        $client->request('GET', '/api/v1/storefront/reference');
        self::assertResponseIsSuccessful();

        /** @var list<array<string, mixed>> $categories */
        $categories = $this->json($client)['categories'] ?? [];

        return $categories;
    }

    /** @return array<string, mixed> */
    private function categoryPayload(KernelBrowser $client, string $slug): array
    {
        $payload = $this->categoryPayloadOrNull($client, $slug);
        self::assertNotNull($payload, 'the category '.$slug.' must be readable by the admin list');

        return $payload;
    }

    /** @return array<string, mixed>|null */
    private function categoryPayloadOrNull(KernelBrowser $client, string $slug): ?array
    {
        foreach ($this->adminList($client) as $item) {
            if ($item['slug'] === $slug) {
                return $item;
            }
        }

        return null;
    }

    private function uniqueSlug(string $prefix): string
    {
        $slug = $prefix.'-'.substr(str_replace('-', '', Uuid::v7()->toRfc4122()), 0, 12);
        $this->categorySlugs[] = $slug;

        return $slug;
    }

    private function db(): Connection
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        return $connection;
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }
}
