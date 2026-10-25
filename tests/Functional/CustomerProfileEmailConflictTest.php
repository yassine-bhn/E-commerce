<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * spec-run-12 R10 at the endpoint — an address another account already owns is a conflict, not a 500.
 *
 * The profile update writes the address the customer typed, so the unique index on lower(email)
 * (Version20261024000000) is the only guard on this path: there is no pre-check to make the race
 * unlikely. Before the constraint the request silently created a second account for one address;
 * with it, the answer has to be the same 409 registration answers.
 */
final class CustomerProfileEmailConflictTest extends WebTestCase
{
    /** @var list<string> */
    private array $createdUserIds = [];

    protected function tearDown(): void
    {
        if ([] !== $this->createdUserIds) {
            self::connection()->executeStatement(
                'DELETE FROM customer_profile WHERE id IN (:ids)',
                ['ids' => $this->createdUserIds],
                ['ids' => ArrayParameterType::STRING],
            );
            $this->createdUserIds = [];
        }

        parent::tearDown();
    }

    public function testClaimingAnAddressAnotherAccountOwnsAnswersConflictAndChangesNothing(): void
    {
        $client = static::createClient();
        $owner = $this->createCustomer('profile.owner');
        $other = $this->createCustomer('profile.other');

        $client->loginUser($owner);

        $client->request(
            'PUT',
            '/api/v1/storefront/customer/profile',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Nom jamais enregistré',
                // Same address, other case: the constraint is on lower(email), so this is a claim.
                'email' => strtoupper((string) $other->getUserIdentifier()),
                'phone' => '+21622334455',
                'type' => 'particulier',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a duplicate address answers the conflict registration answers');

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsStringIgnoringCase((string) $other->getUserIdentifier(), (string) $payload['error']);

        $persisted = self::connection()->fetchAssociative(
            'SELECT name, email FROM customer_profile WHERE id = :id',
            ['id' => $owner->getId()],
        );
        self::assertIsArray($persisted);
        self::assertSame($owner->getUserIdentifier(), $persisted['email'], 'the refused claim left the address untouched');
        self::assertNotSame('Nom jamais enregistré', $persisted['name'], 'the whole update was rolled back, not just the address');
    }

    private function createCustomer(string $prefix): CustomerUser
    {
        $id = Uuid::v4()->toRfc4122();
        $email = sprintf('%s.%s@example.com', $prefix, substr($id, 0, 8));

        self::connection()->insert('customer_profile', [
            'id' => $id,
            'name' => 'Client '.$prefix,
            'email' => $email,
            'phone' => '',
            'role' => 'ROLE_USER',
            'status' => 'active',
        ]);
        $this->createdUserIds[] = $id;

        return new CustomerUser($id, $email, null, 'ROLE_USER', 'active');
    }

    private static function connection(): Connection
    {
        $connection = static::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);

        return $connection;
    }
}
