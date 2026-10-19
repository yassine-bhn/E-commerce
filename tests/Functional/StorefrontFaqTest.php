<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StorefrontFaqTest extends WebTestCase
{
    public function testFaqEndpointIsPublicAndOrderedBySortOrder(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/storefront/faq');

        self::assertResponseIsSuccessful();

        /** @var array{items: list<array<string, mixed>>} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertNotEmpty($data['items']);

        foreach ($data['items'] as $item) {
            self::assertArrayHasKey('id', $item);
            self::assertArrayHasKey('category', $item);
            self::assertArrayHasKey('question', $item);
            self::assertArrayHasKey('answer', $item);
        }

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $expectedIds = $connection->fetchFirstColumn(
            'SELECT id FROM storefront_faq_entry ORDER BY sort_order ASC, question ASC',
        );
        $actualIds = array_map(
            static fn (array $item): string => (string) $item['id'],
            $data['items'],
        );

        self::assertSame($expectedIds, $actualIds);
    }
}
