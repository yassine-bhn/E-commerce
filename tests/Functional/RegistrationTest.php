<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class RegistrationTest extends WebTestCase
{
    /** @var list<string> */
    private array $createdEmails = [];

    protected function tearDown(): void
    {
        if ([] !== $this->createdEmails) {
            /** @var Connection $connection */
            $connection = static::getContainer()->get(Connection::class);
            foreach ($this->createdEmails as $email) {
                $connection->executeStatement(
                    'DELETE FROM customer_profile WHERE lower(email) = lower(:email)',
                    ['email' => $email],
                );
            }
            $this->createdEmails = [];
        }

        parent::tearDown();
    }

    public function testRegistrationCreatesActiveCustomerAndAllowsLogin(): void
    {
        $client = static::createClient();

        $email = 'register.'.Uuid::v4()->toRfc4122().'@example.com';
        $password = 'StrongPass!234';
        $this->createdEmails[] = $email;

        $client->jsonRequest('POST', '/api/v1/auth/register', [
            'name' => 'Nouvelle Cliente',
            'email' => $email,
            'password' => $password,
            'phone' => '+21620123456',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertNotEmpty($payload['id'] ?? null);
        self::assertSame($email, $payload['email'] ?? null);
        self::assertSame(['ROLE_USER'], $payload['roles'] ?? null);
        self::assertSame('active', $payload['status'] ?? null);

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $row = $connection->fetchAssociative(
            'SELECT id, role, status, password_hash FROM customer_profile WHERE lower(email) = lower(:email)',
            ['email' => $email],
        );
        self::assertIsArray($row);
        self::assertSame($payload['id'], $row['id']);
        self::assertSame('ROLE_USER', $row['role']);
        self::assertSame('active', $row['status']);
        self::assertNotNull($row['password_hash']);

        // A subsequent login with the registered password must succeed.
        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);

        self::assertResponseIsSuccessful();
        $loginPayload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($payload['id'], $loginPayload['id'] ?? null);
        self::assertSame($email, $loginPayload['email'] ?? null);
    }

    public function testRegistrationWithExistingEmailReturns409AndDoesNotDuplicate(): void
    {
        $client = static::createClient();

        $email = 'duplicate.'.Uuid::v4()->toRfc4122().'@example.com';
        $this->createdEmails[] = $email;

        $client->jsonRequest('POST', '/api/v1/auth/register', [
            'name' => 'Premier Compte',
            'email' => $email,
            'password' => 'StrongPass!234',
            'phone' => '+21620123456',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $countBefore = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM customer_profile WHERE lower(email) = lower(:email)',
            ['email' => $email],
        );
        self::assertSame(1, $countBefore);

        // Attempt a second registration with a different casing to prove case-insensitive uniqueness.
        $client->jsonRequest('POST', '/api/v1/auth/register', [
            'name' => 'Compte en Double',
            'email' => strtoupper($email),
            'password' => 'AnotherPass!234',
            'phone' => '+21620123456',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($payload['error'] ?? null);

        $countAfter = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM customer_profile WHERE lower(email) = lower(:email)',
            ['email' => $email],
        );
        self::assertSame($countBefore, $countAfter);
    }

    public function testRegistrationWithInvalidEmailReturns400(): void
    {
        $client = static::createClient();

        $client->jsonRequest('POST', '/api/v1/auth/register', [
            'name' => 'Email Invalide',
            'email' => 'not-an-email',
            'password' => 'StrongPass!234',
            'phone' => '+21620123456',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($payload['error'] ?? null);
    }

    public function testRegistrationWithShortPasswordReturns400(): void
    {
        $client = static::createClient();

        $email = 'shortpass.'.Uuid::v4()->toRfc4122().'@example.com';
        $this->createdEmails[] = $email;

        $client->jsonRequest('POST', '/api/v1/auth/register', [
            'name' => 'Mot de Passe Court',
            'email' => $email,
            'password' => 'short',
            'phone' => '+21620123456',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($payload['error'] ?? null);
    }
}
