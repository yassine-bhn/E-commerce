<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * R6 — product reviews paginate by keyset on `(created_at, id)` while `count` and
 * `averageRating` stay computed over every review of the product.
 */
final class StorefrontProductReviewsPaginationTest extends WebTestCase
{
    private KernelBrowser $client;

    private string $slug;

    /** @var list<string> */
    private array $reviewIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $this->slug = 'it-reviews-'.bin2hex(random_bytes(4));

        // 12 reviews: seven 5-star, three 4-star and two 2-star.
        $ratings = [5, 5, 5, 5, 5, 5, 5, 4, 4, 4, 2, 2];
        $base = new \DateTimeImmutable('2026-01-01 10:00:00+00:00');
        $connection = self::connection();

        foreach ($ratings as $index => $rating) {
            $id = sprintf('0192a000-7000-7000-8000-%012d', $index);
            $connection->insert(
                'storefront_product_review',
                [
                    'id' => $id,
                    'product_slug' => $this->slug,
                    'author_name' => 'Reviewer '.$index,
                    'rating' => $rating,
                    'comment' => 'Review '.$index,
                    'created_at' => $base->modify(sprintf('+%d seconds', $index))->format('Y-m-d H:i:sP'),
                ],
                [
                    'rating' => ParameterType::INTEGER,
                ],
            );
            $this->reviewIds[] = $id;
        }
    }

    protected function tearDown(): void
    {
        if ([] !== $this->reviewIds) {
            self::connection()->executeStatement(
                'DELETE FROM storefront_product_review WHERE id IN (:ids)',
                ['ids' => $this->reviewIds],
                ['ids' => ArrayParameterType::STRING],
            );
        }
        $this->reviewIds = [];

        parent::tearDown();
    }

    public function testTheFirstPageIsBoundedAndReportsTheGlobalAggregates(): void
    {
        $this->client->request('GET', sprintf('/api/v1/storefront/products/%s/reviews?limit=5', $this->slug));
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> $data */
        $data = $this->json();

        self::assertSame($this->slug, $data['slug']);
        self::assertSame(12, $data['count'], 'count is the total number of reviews, not the page size');
        self::assertSame(4.3, $data['averageRating'], 'the average covers every review of the product');
        self::assertCount(5, $data['items']);
        self::assertSame(5, $data['meta']['limit']);
        self::assertTrue($data['meta']['hasMore']);
        self::assertIsString($data['meta']['nextCursor']);
    }

    public function testWalkingTheCursorReturnsEveryReviewExactlyOnceInDescendingOrder(): void
    {
        $collected = [];
        $cursor = null;

        do {
            $url = sprintf('/api/v1/storefront/products/%s/reviews?limit=5', $this->slug);
            if (null !== $cursor) {
                $url .= '&cursor='.rawurlencode($cursor);
            }

            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            /** @var array<string, mixed> $data */
            $data = $this->json();

            foreach ($data['items'] as $item) {
                $collected[] = $item['id'];
            }

            $cursor = $data['meta']['nextCursor'];
        } while (null !== $cursor);

        self::assertCount(12, $collected, 'pages are contiguous: nothing is skipped');
        self::assertSame($collected, array_values(array_unique($collected)), 'pages never repeat a review');
        self::assertSame(array_reverse($this->reviewIds), $collected, 'newest first: created_at DESC, id DESC');
    }

    public function testTheRatingFilterNarrowsTheItemsButNotTheAggregates(): void
    {
        $this->client->request(
            'GET',
            sprintf('/api/v1/storefront/products/%s/reviews?rating=5&limit=50', $this->slug),
        );
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> $data */
        $data = $this->json();

        self::assertCount(7, $data['items']);
        foreach ($data['items'] as $item) {
            self::assertSame(5, $item['rating']);
        }

        self::assertSame(12, $data['count'], 'the rating filter never changes the global count');
        self::assertSame(4.3, $data['averageRating'], 'the rating filter never changes the global average');
        self::assertFalse($data['meta']['hasMore']);
        self::assertNull($data['meta']['nextCursor']);
    }

    public function testAnUnknownProductHasAnEmptyPageAndZeroAggregates(): void
    {
        $this->client->request('GET', '/api/v1/storefront/products/does-not-exist-it/reviews');
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> $data */
        $data = $this->json();

        self::assertSame(0, $data['count']);
        self::assertSame(0.0, $data['averageRating']);
        self::assertSame([], $data['items']);
        self::assertFalse($data['meta']['hasMore']);
    }

    public function testTheLimitIsClampedInsteadOfRejected(): void
    {
        $this->client->request(
            'GET',
            sprintf('/api/v1/storefront/products/%s/reviews?limit=500', $this->slug),
        );
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> $data */
        $data = $this->json();

        self::assertSame(50, $data['meta']['limit'], 'limit is clamped to the documented maximum');
        self::assertCount(12, $data['items']);
    }

    public function testAnUndecodableCursorIsABadRequest(): void
    {
        $this->client->request(
            'GET',
            sprintf('/api/v1/storefront/products/%s/reviews?cursor=not-base64', $this->slug),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame(['error' => 'Invalid cursor'], $this->json());
    }

    public function testACursorWithAMalformedPayloadIsABadRequest(): void
    {
        $raw = rtrim(strtr(base64_encode('{"k":"2026-01-01 10:00:00+00"}'), '+/', '-_'), '=');

        $this->client->request(
            'GET',
            sprintf('/api/v1/storefront/products/%s/reviews?cursor=%s', $this->slug, rawurlencode($raw)),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame(['error' => 'Invalid cursor'], $this->json());
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
