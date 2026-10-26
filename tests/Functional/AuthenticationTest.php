<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class AuthenticationTest extends WebTestCase
{
    /** @var list<string> */
    private array $createdUserIds = [];

    protected function tearDown(): void
    {
        if ([] !== $this->createdUserIds) {
            /** @var Connection $connection */
            $connection = static::getContainer()->get(Connection::class);
            $connection->executeStatement(
                'DELETE FROM identity_audit_log WHERE target_user_id IN (:ids)',
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

    private function createCustomerUser(
        string $role = 'ROLE_USER',
        string $status = 'active',
        ?string $email = null,
        ?string $password = null,
    ): CustomerUser {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $id = Uuid::v4()->toRfc4122();
        $email = $email ?? sprintf('test.%s.%s@example.com', strtolower(str_replace('ROLE_', '', $role)), substr($id, 0, 8));

        $connection->insert('customer_profile', [
            'id' => $id,
            'name' => 'Test User '.$role,
            'email' => $email,
            'password_hash' => null !== $password ? password_hash($password, PASSWORD_BCRYPT) : null,
            'role' => $role,
            'status' => $status,
        ]);

        $this->createdUserIds[] = $id;

        return new CustomerUser($id, $email, null, $role, $status);
    }

    public function testLoginWithValidCredentialsReturnsCustomerIdentity(): void
    {
        $client = static::createClient();

        $email = 'auth.login.'.Uuid::v4()->toRfc4122().'@example.com';
        $password = 'CorrectHorseBatteryStaple!42';
        $user = $this->createCustomerUser('ROLE_USER', 'active', $email, $password);

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame($user->getId(), $payload['id'] ?? null);
        self::assertSame($email, $payload['email'] ?? null);
        self::assertContains('ROLE_USER', $payload['roles'] ?? []);
    }

    public function testLoginWithInvalidCredentialsReturns401(): void
    {
        $client = static::createClient();

        $email = 'wrong.pass.'.Uuid::v4()->toRfc4122().'@example.com';
        $this->createCustomerUser('ROLE_USER', 'active', $email, 'RealPassword123!');

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => 'WrongPassword999!',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Invalid credentials.', $payload['error'] ?? null);
    }

    public function testBannedCustomerCannotLogin(): void
    {
        $client = static::createClient();

        $email = 'banned.'.Uuid::v4()->toRfc4122().'@example.com';
        $this->createCustomerUser('ROLE_USER', 'banned', $email, 'Secret123!');

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Secret123!',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testMeEndpointReturnsAuthenticatedProfileOr401(): void
    {
        $client = static::createClient();

        // 1. Unauthenticated request
        $client->request('GET', '/api/v1/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        // 2. Authenticated request
        $user = $this->createCustomerUser('ROLE_USER', 'active');
        $client->loginUser($user);

        $client->request('GET', '/api/v1/auth/me');
        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($user->getId(), $payload['id']);
        self::assertSame($user->getEmail(), $payload['email']);
        self::assertContains('ROLE_USER', $payload['roles']);
        self::assertSame('active', $payload['status']);
    }

    public function testLogoutEndpointClearsSession(): void
    {
        $client = static::createClient();
        $user = $this->createCustomerUser('ROLE_USER', 'active');
        $client->loginUser($user);

        $client->request('POST', '/api/v1/auth/logout');
        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['success'] ?? false);
    }

    public function testAdminRouteDeniesUnauthenticatedGuestEvenWithAdminHeader(): void
    {
        $client = static::createClient();

        // 1. Without header
        $client->jsonRequest('POST', '/api/v1/admin/orders/proxy', [
            'orderSource' => 'WHATSAPP',
            'customerName' => 'Guest Target',
            'customerPhone' => '+21620000000',
            'shippingAddress' => 'Tunis',
            'lines' => [['slug' => 'baignoire-d-code', 'qty' => 1]],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        // 2. With spoofed X-Admin-Id header
        $client->request(
            'POST',
            '/api/v1/admin/orders/proxy',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_ADMIN_ID' => 'spoofed-admin-id'],
            json_encode([
                'orderSource' => 'WHATSAPP',
                'customerName' => 'Guest Target',
                'customerPhone' => '+21620000000',
                'shippingAddress' => 'Tunis',
                'lines' => [['slug' => 'baignoire-d-code', 'qty' => 1]],
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Authentication required.', $payload['error'] ?? null);
    }

    public function testAdminRouteDeniesStandardRoleUser(): void
    {
        $client = static::createClient();
        $user = $this->createCustomerUser('ROLE_USER', 'active');
        $client->loginUser($user);

        $client->jsonRequest('POST', '/api/v1/admin/orders/proxy', [
            'orderSource' => 'WHATSAPP',
            'customerName' => 'Target Customer',
            'customerPhone' => '+21620000000',
            'shippingAddress' => 'Tunis',
            'lines' => [['slug' => 'baignoire-d-code', 'qty' => 1]],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Access denied.', $payload['error'] ?? null);
    }

    public function testAdminRouteAllowsRoleAdminAndResolvesActor(): void
    {
        $client = static::createClient();
        $admin = $this->createCustomerUser('ROLE_ADMIN', 'active');
        $client->loginUser($admin);

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $client->jsonRequest('POST', '/api/v1/admin/orders/proxy', [
            'orderSource' => 'WHATSAPP',
            'customerName' => 'Proxy Client',
            'customerPhone' => '+21699887766',
            'shippingAddress' => 'Sousse',
            'lines' => [['slug' => 'baignoire-d-code', 'qty' => 1]],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['success'] ?? false);
        $orderId = (string) $payload['orderId'];

        // Verify created_by_admin_id in database is resolved from authenticated admin
        $recordedAdmin = $connection->fetchOne('SELECT created_by_admin_id FROM ordering_orders WHERE id = :id', ['id' => $orderId]);
        self::assertSame($admin->getId(), $recordedAdmin);
    }

    public function testSuperAdminOnlyRouteDeniesStandardAdmin(): void
    {
        $client = static::createClient();
        $admin = $this->createCustomerUser('ROLE_ADMIN', 'active');
        $client->loginUser($admin);

        // Accepting a submission is the one decision that stays above ROLE_ADMIN.
        $client->request('POST', '/api/v1/admin/catalog/submissions/products/whatever-slug/approve');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Access denied.', $payload['error'] ?? null);
    }

    public function testSuperAdminCanDeleteCustomerAndRecordsAudit(): void
    {
        $client = static::createClient();
        $superAdmin = $this->createCustomerUser('ROLE_SUPER_ADMIN', 'active');
        $client->loginUser($superAdmin);

        $target = $this->createCustomerUser('ROLE_USER', 'active');

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $client->request('DELETE', '/api/v1/admin/customers/'.$target->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reason' => 'GDPR Right to be forgotten',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();

        // Verify audit log has the superadmin's ID
        $audit = $connection->fetchAssociative(
            'SELECT actor_admin_id, reason FROM identity_audit_log WHERE target_user_id = :id ORDER BY created_at DESC LIMIT 1',
            ['id' => $target->getId()],
        );
        self::assertIsArray($audit);
        self::assertSame($superAdmin->getId(), $audit['actor_admin_id']);
        self::assertSame('GDPR Right to be forgotten', $audit['reason']);
    }

    public function testLastSuperAdminCannotBeRemoved(): void
    {
        $client = static::createClient();

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        // Delete any other superadmins so we test the exact guard
        $connection->executeStatement("DELETE FROM customer_profile WHERE role = 'ROLE_SUPER_ADMIN'");

        $soleSuperAdmin = $this->createCustomerUser('ROLE_SUPER_ADMIN', 'active');
        $client->loginUser($soleSuperAdmin);

        $client->request('DELETE', '/api/v1/admin/customers/'.$soleSuperAdmin->getId());
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('at least one active SuperAdmin', $payload['error'] ?? '');
    }

    public function testAdminCannotBanAdminOrSuperAdmin(): void
    {
        $client = static::createClient();
        $admin = $this->createCustomerUser('ROLE_ADMIN', 'active');
        $client->loginUser($admin);

        $colleague = $this->createCustomerUser('ROLE_ADMIN', 'active');

        $client->jsonRequest('POST', '/api/v1/admin/customers/'.$colleague->getId().'/ban', [
            'reason' => 'Dispute between admins',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('cannot be banned', $payload['error'] ?? '');
    }

    public function testAdminCanBanStandardUserAndRecordsAudit(): void
    {
        $client = static::createClient();
        $admin = $this->createCustomerUser('ROLE_ADMIN', 'active');
        $client->loginUser($admin);

        $badUser = $this->createCustomerUser('ROLE_USER', 'active');

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $client->jsonRequest('POST', '/api/v1/admin/customers/'.$badUser->getId().'/ban', [
            'reason' => 'Repeated fraudulent transactions',
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('banned', $payload['status']);

        // Verify customer is marked banned in DB
        $dbStatus = $connection->fetchOne('SELECT status FROM customer_profile WHERE id = :id', ['id' => $badUser->getId()]);
        self::assertSame('banned', $dbStatus);

        // Verify audit log
        $audit = $connection->fetchAssociative(
            'SELECT actor_admin_id, action FROM identity_audit_log WHERE target_user_id = :id AND action = :action',
            ['id' => $badUser->getId(), 'action' => 'USER_BANNED'],
        );
        self::assertIsArray($audit);
        self::assertSame($admin->getId(), $audit['actor_admin_id']);
    }

    public function testCustomerEndpointsRequireRoleUserAndIgnoreManualHeaders(): void
    {
        $client = static::createClient();
        $fakeCustomerId = Uuid::v4()->toRfc4122();

        // 1. Guest calling profile without auth gets 401 even if sending X-Customer-Id
        $client->request(
            'GET',
            '/api/v1/storefront/customer/profile',
            [],
            [],
            ['HTTP_X_CUSTOMER_ID' => $fakeCustomerId],
        );
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        // 2. Authenticated user Alice cannot spoof Bob by sending X-Customer-Id
        $userAlice = $this->createCustomerUser('ROLE_USER', 'active');
        $userBob = $this->createCustomerUser('ROLE_USER', 'active');

        $client->loginUser($userAlice);

        $client->request(
            'GET',
            '/api/v1/storefront/customer/profile',
            [],
            [],
            ['HTTP_X_CUSTOMER_ID' => $userBob->getId()],
        );

        self::assertResponseIsSuccessful();
        $profile = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        // Must return Alice's profile, NOT Bob's!
        self::assertSame($userAlice->getId(), $profile['id']);
        self::assertSame($userAlice->getEmail(), $profile['email']);
    }

    public function testGuestQuoteRequestAllowedAndAnonymous(): void
    {
        $client = static::createClient();

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $email = 'guest.'.Uuid::v4()->toRfc4122().'@example.com';

        $client->jsonRequest('POST', '/api/v1/storefront/quote-requests', [
            'full_name' => 'Visiteur Anonyme',
            'email' => $email,
            'phone' => '+21699000111',
            'category' => 'chauffage',
            'subcategories' => ['Chauffage central'],
            'message' => 'Demande de devis pour rénovation chaudière sans compte.',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $quote = $connection->fetchAssociative(
            'SELECT user_id, email FROM identity_quote_requests WHERE email = :email ORDER BY created_at DESC LIMIT 1',
            ['email' => $email],
        );
        self::assertIsArray($quote);
        self::assertNull($quote['user_id']);

        $connection->executeStatement('DELETE FROM identity_quote_requests WHERE email = :email', ['email' => $email]);
    }

    public function testAuthenticatedQuoteRequestLinksToAccount(): void
    {
        $client = static::createClient();
        $user = $this->createCustomerUser('ROLE_USER', 'active');
        $client->loginUser($user);

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $email = 'user.quote.'.Uuid::v4()->toRfc4122().'@example.com';

        $client->jsonRequest('POST', '/api/v1/storefront/quote-requests', [
            'full_name' => 'Client Authentifié',
            'email' => $email,
            'phone' => '+21699000222',
            'category' => 'chauffage',
            'subcategories' => ['Chauffage central'],
            'message' => 'Demande de devis liée à mon compte client.',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $quote = $connection->fetchAssociative(
            'SELECT user_id, email FROM identity_quote_requests WHERE email = :email ORDER BY created_at DESC LIMIT 1',
            ['email' => $email],
        );
        self::assertIsArray($quote);
        self::assertSame($user->getId(), $quote['user_id']);

        $connection->executeStatement('DELETE FROM identity_quote_requests WHERE email = :email', ['email' => $email]);
    }

    public function testGuestCannotListCustomerQuoteRequests(): void
    {
        $client = static::createClient();

        // Guest attempting to list quotes gets 401
        $client->request('GET', '/api/v1/storefront/quote-requests');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        // Authenticated user can list quotes
        $user = $this->createCustomerUser('ROLE_USER', 'active');
        $client->loginUser($user);

        $client->request('GET', '/api/v1/storefront/quote-requests');
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('items', $payload);
    }
}
