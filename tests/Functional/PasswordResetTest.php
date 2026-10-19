<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class PasswordResetTest extends WebTestCase
{
    /** @var list<string> */
    private array $createdUserIds = [];

    protected function tearDown(): void
    {
        if ([] !== $this->createdUserIds) {
            /** @var Connection $connection */
            $connection = static::getContainer()->get(Connection::class);
            $connection->executeStatement(
                'DELETE FROM customer_password_reset_token WHERE customer_id IN (:ids)',
                ['ids' => $this->createdUserIds],
                ['ids' => ArrayParameterType::STRING],
            );
            $connection->executeStatement(
                'DELETE FROM customer_profile WHERE id IN (:ids)',
                ['ids' => $this->createdUserIds],
                ['ids' => ArrayParameterType::STRING],
            );
            $this->createdUserIds = [];
        }

        parent::tearDown();
    }

    /**
     * @return array{id: string, email: string}
     */
    private function createCustomer(Connection $connection, string $password): array
    {
        $id = Uuid::v4()->toRfc4122();
        $email = sprintf('reset.%s@example.com', $id);

        $connection->insert('customer_profile', [
            'id' => $id,
            'name' => 'Réinitialisation Test',
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'role' => 'ROLE_USER',
            'status' => 'active',
        ]);

        $this->createdUserIds[] = $id;

        return ['id' => $id, 'email' => $email];
    }

    private function insertToken(
        Connection $connection,
        string $customerId,
        string $token,
        bool $expired = false,
        bool $used = false,
    ): void {
        $expiresAt = (new \DateTimeImmutable($expired ? '-1 hour' : '+1 hour'))->format('Y-m-d H:i:sP');
        $usedAt = $used ? (new \DateTimeImmutable())->format('Y-m-d H:i:sP') : null;

        $connection->executeStatement(
            'INSERT INTO customer_password_reset_token (id, customer_id, token_hash, expires_at, used_at, created_at)
             VALUES (:id, :customer, :token_hash, :expires_at, :used_at, CURRENT_TIMESTAMP)',
            [
                'id' => Uuid::v4()->toRfc4122(),
                'customer' => $customerId,
                'token_hash' => hash('sha256', $token),
                'expires_at' => $expiresAt,
                'used_at' => $usedAt,
            ],
        );
    }

    public function testForgotPasswordWithExistingEmailCreatesTokenAndStaysNeutral(): void
    {
        $client = static::createClient();

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $customer = $this->createCustomer($connection, 'OldPass!234');

        $countBefore = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM customer_password_reset_token WHERE customer_id = :id',
            ['id' => $customer['id']],
        );

        $client->jsonRequest('POST', '/api/v1/auth/forgot-password', [
            'email' => $customer['email'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status'] ?? null);

        $countAfter = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM customer_password_reset_token WHERE customer_id = :id',
            ['id' => $customer['id']],
        );
        self::assertSame($countBefore + 1, $countAfter);
    }

    public function testForgotPasswordWithUnknownEmailCreatesNoTokenAndStaysNeutral(): void
    {
        $client = static::createClient();

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $countBefore = (int) $connection->fetchOne('SELECT COUNT(*) FROM customer_password_reset_token');

        $client->jsonRequest('POST', '/api/v1/auth/forgot-password', [
            'email' => 'unknown.'.Uuid::v4()->toRfc4122().'@example.com',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status'] ?? null);

        $countAfter = (int) $connection->fetchOne('SELECT COUNT(*) FROM customer_password_reset_token');
        self::assertSame($countBefore, $countAfter);
    }

    public function testResetPasswordWithValidTokenUpdatesPasswordAndConsumesToken(): void
    {
        $client = static::createClient();

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $oldPassword = 'OldPass!234';
        $newPassword = 'NewPass!567';
        $customer = $this->createCustomer($connection, $oldPassword);

        $token = 'jeton-test-'.Uuid::v4()->toRfc4122();
        $this->insertToken($connection, $customer['id'], $token);

        $client->jsonRequest('POST', '/api/v1/auth/reset-password', [
            'token' => $token,
            'password' => $newPassword,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('reset', $payload['status'] ?? null);

        $usedAt = $connection->fetchOne(
            'SELECT used_at FROM customer_password_reset_token WHERE token_hash = :hash',
            ['hash' => hash('sha256', $token)],
        );
        self::assertNotNull($usedAt);

        // The old password no longer authenticates.
        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => $customer['email'],
            'password' => $oldPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        // The new password authenticates.
        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => $customer['email'],
            'password' => $newPassword,
        ]);
        self::assertResponseIsSuccessful();
        $loginPayload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($customer['email'], $loginPayload['email'] ?? null);
    }

    public function testResetPasswordWithUnknownTokenReturns400(): void
    {
        $client = static::createClient();

        $client->jsonRequest('POST', '/api/v1/auth/reset-password', [
            'token' => 'jeton-inconnu-'.Uuid::v4()->toRfc4122(),
            'password' => 'NewPass!567',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($payload['error'] ?? null);
    }

    public function testResetPasswordWithExpiredTokenReturns400AndKeepsPassword(): void
    {
        $client = static::createClient();

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $customer = $this->createCustomer($connection, 'OldPass!234');

        $hashBefore = $connection->fetchOne(
            'SELECT password_hash FROM customer_profile WHERE id = :id',
            ['id' => $customer['id']],
        );

        $token = 'jeton-expire-'.Uuid::v4()->toRfc4122();
        $this->insertToken($connection, $customer['id'], $token, expired: true);

        $client->jsonRequest('POST', '/api/v1/auth/reset-password', [
            'token' => $token,
            'password' => 'NewPass!567',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($payload['error'] ?? null);

        $hashAfter = $connection->fetchOne(
            'SELECT password_hash FROM customer_profile WHERE id = :id',
            ['id' => $customer['id']],
        );
        self::assertSame($hashBefore, $hashAfter);
    }

    public function testResetPasswordWithUsedTokenReturns400(): void
    {
        $client = static::createClient();

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $customer = $this->createCustomer($connection, 'OldPass!234');

        $token = 'jeton-utilise-'.Uuid::v4()->toRfc4122();
        $this->insertToken($connection, $customer['id'], $token, used: true);

        $client->jsonRequest('POST', '/api/v1/auth/reset-password', [
            'token' => $token,
            'password' => 'NewPass!567',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($payload['error'] ?? null);
    }
}
