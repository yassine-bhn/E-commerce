<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * R8 — `GET /api/v1/storefront/quote-requests` paginates by keyset on `(created_at, id)` and
 * filters on status, dates and free text, always scoped to the authenticated user.
 */
final class StorefrontQuoteRequestsPaginationTest extends WebTestCase
{
    private KernelBrowser $client;

    private CustomerUser $customer;

    /** @var list<string> */
    private array $quoteIds = [];

    /** @var list<string> */
    private array $customerIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->customer = $this->createCustomer('quotes.tester');
        $this->client->loginUser($this->customer);

        $this->insertQuote('chauffage', 'Devis chaudière', 'nouveau', '2026-05-01 08:00:00.123456+00');
        $this->insertQuote('sanitaires', 'Devis salle de bain', 'nouveau', '2026-05-02 08:00:00.500000+00');
        $this->insertQuote('climatisation', 'Devis climatiseur', 'en_cours', '2026-05-03 08:00:00.000001+00');
        $this->insertQuote('chauffage', 'Devis radiateur', 'traite', '2026-05-04 08:00:00.999999+00');
        $this->insertQuote('plomberie', 'Devis fuite', 'traite', '2026-05-05 08:00:00.000000+00');
    }

    protected function tearDown(): void
    {
        $connection = self::connection();

        if ([] !== $this->quoteIds) {
            $connection->executeStatement(
                'DELETE FROM identity_quote_requests WHERE id IN (:ids)',
                ['ids' => $this->quoteIds],
                ['ids' => ArrayParameterType::STRING],
            );
        }

        if ([] !== $this->customerIds) {
            $connection->executeStatement(
                'DELETE FROM customer_profile WHERE id IN (:ids)',
                ['ids' => $this->customerIds],
                ['ids' => ArrayParameterType::STRING],
            );
        }

        $this->quoteIds = [];
        $this->customerIds = [];

        parent::tearDown();
    }

    public function testTheFirstPageIsBoundedAndNewestFirst(): void
    {
        $this->client->request('GET', '/api/v1/storefront/quote-requests?limit=2');
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> $data */
        $data = $this->json();

        self::assertSame(2, $data['meta']['limit']);
        self::assertTrue($data['meta']['hasMore']);
        self::assertIsString($data['meta']['nextCursor']);
        self::assertSame(
            ['Devis fuite', 'Devis radiateur'],
            array_column($data['items'], 'message'),
            'created_at DESC, id DESC',
        );
    }

    public function testWalkingTheCursorReturnsEveryRequestExactlyOnce(): void
    {
        $collected = [];
        $cursor = null;

        do {
            $url = '/api/v1/storefront/quote-requests?limit=2';
            if (null !== $cursor) {
                $url .= '&cursor='.rawurlencode($cursor);
            }

            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            /** @var array<string, mixed> $data */
            $data = $this->json();

            foreach ($data['items'] as $item) {
                $collected[] = $item['message'];
            }

            $cursor = $data['meta']['nextCursor'];
        } while (null !== $cursor);

        self::assertSame(
            ['Devis fuite', 'Devis radiateur', 'Devis climatiseur', 'Devis salle de bain', 'Devis chaudière'],
            $collected,
            'microsecond timestamps keep the pages contiguous',
        );
    }

    public function testTheStatusFilterIsExact(): void
    {
        $this->client->request('GET', '/api/v1/storefront/quote-requests?status=traite');
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> $data */
        $data = $this->json();
        self::assertSame(['Devis fuite', 'Devis radiateur'], array_column($data['items'], 'message'));

        $this->client->request('GET', '/api/v1/storefront/quote-requests?status=inconnu');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = $this->json();
        self::assertSame([], $data['items']);
    }

    public function testTheDateRangeIsInclusiveOnBothEnds(): void
    {
        $this->client->request('GET', '/api/v1/storefront/quote-requests?dateFrom=2026-05-02&dateTo=2026-05-04');
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> $data */
        $data = $this->json();
        self::assertSame(
            ['Devis radiateur', 'Devis climatiseur', 'Devis salle de bain'],
            array_column($data['items'], 'message'),
        );
    }

    public function testTheQFilterMatchesCategoryAndMessage(): void
    {
        $this->client->request('GET', '/api/v1/storefront/quote-requests?q=chauffage');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = $this->json();
        self::assertSame(['Devis radiateur', 'Devis chaudière'], array_column($data['items'], 'message'));

        $this->client->request('GET', '/api/v1/storefront/quote-requests?q=climatiseur');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = $this->json();
        self::assertSame(['Devis climatiseur'], array_column($data['items'], 'message'));
    }

    public function testFiltersNeverLeakAnotherUsersRequests(): void
    {
        $other = $this->createCustomer('quotes.other');
        $this->insertQuote('chauffage', 'Devis prive', 'traite', '2026-05-06 08:00:00.000000+00', $other->getId());

        $this->client->request('GET', '/api/v1/storefront/quote-requests?status=traite&q=prive');
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> $data */
        $data = $this->json();
        self::assertSame([], $data['items'], 'the owner scope applies whatever the filters');
    }

    public function testAnInvalidCursorIsABadRequest(): void
    {
        $this->client->request('GET', '/api/v1/storefront/quote-requests?cursor=not-base64');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame(['error' => 'Invalid cursor'], $this->json());
    }

    public function testTheLimitIsClampedInsteadOfRejected(): void
    {
        $this->client->request('GET', '/api/v1/storefront/quote-requests?limit=5000');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = $this->json();
        self::assertSame(100, $data['meta']['limit']);
        self::assertCount(5, $data['items']);
    }

    private function createCustomer(string $prefix): CustomerUser
    {
        $id = Uuid::v4()->toRfc4122();
        $email = sprintf('%s.%s@example.com', $prefix, substr($id, 0, 8));

        self::connection()->insert('customer_profile', [
            'id' => $id,
            'name' => 'Quotes Tester',
            'email' => $email,
            'password_hash' => password_hash('Quotes!234', PASSWORD_BCRYPT),
            'role' => 'ROLE_USER',
            'status' => 'active',
        ]);
        $this->customerIds[] = $id;

        return new CustomerUser($id, $email, null, 'ROLE_USER', 'active');
    }

    private function insertQuote(string $category, string $message, string $status, string $createdAt, ?string $userId = null): void
    {
        $id = Uuid::v4()->toRfc4122();

        self::connection()->insert('identity_quote_requests', [
            'id' => $id,
            'user_id' => $userId ?? $this->customer->getId(),
            'full_name' => 'Quotes Tester',
            'email' => 'quotes.tester@example.com',
            'phone' => '+216 20 00 00 00',
            'city' => 'Tunis',
            'category' => $category,
            'subcategories' => '["Test"]',
            'message' => $message,
            'status' => $status,
            'created_at' => $createdAt,
        ]);

        $this->quoteIds[] = $id;
    }

    private static function connection(): Connection
    {
        $connection = static::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);

        return $connection;
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
