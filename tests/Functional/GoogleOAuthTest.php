<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\GoogleOAuthClient;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Cookie as ResponseCookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class GoogleOAuthTest extends WebTestCase
{
    private const string GOOGLE_EMAIL = 'jane.doe@example.com';
    private const string GOOGLE_SUB = 'google-sub-123';
    private const string REDIRECT_URI = 'http://localhost:8081/api/v1/auth/google/callback';
    private const string STOREFRONT_APP_URL = 'http://localhost:3000';
    private const string REMEMBER_ME_COOKIE = 'REMEMBERME';
    private const string PASSWORD = 'CorrectHorseBatteryStaple!42';

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

    private function useConfiguredClient(): void
    {
        static::getContainer()->set(GoogleOAuthClient::class, new GoogleOAuthClient(
            new MockHttpClient([
                new MockResponse(
                    '{"access_token":"fake-token","token_type":"Bearer","expires_in":3600}',
                    ['response_headers' => ['Content-Type' => 'application/json']],
                ),
                new MockResponse(
                    '{"sub":"google-sub-123","email":"jane.doe@example.com","name":"Jane Doe"}',
                    ['response_headers' => ['Content-Type' => 'application/json']],
                ),
            ]),
            'test-client-id',
            'test-client-secret',
            self::REDIRECT_URI,
        ));
    }

    private function startFlow(KernelBrowser $client): string
    {
        $client->request('GET', '/api/v1/auth/google/redirect');

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);

        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $state = $query['state'] ?? null;

        if (!is_string($state) || '' === $state) {
            self::fail('The Google redirect must carry a non-empty state parameter.');
        }

        return $state;
    }

    /**
     * Returns the remember-me cookies the response would store in a browser: a
     * clearing cookie (empty value) carries no credential and is ignored.
     *
     * @return list<ResponseCookie>
     */
    private function issuedRememberMeCookies(KernelBrowser $client): array
    {
        return array_values(array_filter(
            $client->getResponse()->headers->getCookies(),
            static fn (ResponseCookie $cookie): bool => self::REMEMBER_ME_COOKIE === $cookie->getName()
                && '' !== (string) $cookie->getValue(),
        ));
    }

    /**
     * Returns the cookies the response uses to delete the remember-me credential:
     * an empty value with an expiry in the past.
     *
     * @return list<ResponseCookie>
     */
    private function clearingRememberMeCookies(KernelBrowser $client): array
    {
        return array_values(array_filter(
            $client->getResponse()->headers->getCookies(),
            static fn (ResponseCookie $cookie): bool => self::REMEMBER_ME_COOKIE === $cookie->getName()
                && '' === (string) $cookie->getValue(),
        ));
    }

    /**
     * A browser restart: a fresh kernel carrying no session and no credential.
     */
    private function coldStart(): KernelBrowser
    {
        static::ensureKernelShutdown();

        return static::createClient();
    }

    /**
     * Seeds a password customer, the only kind that can hold a remember-me cookie.
     */
    private function createPasswordCustomer(): string
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $email = sprintf('remember.me.oauth.%s@example.com', substr(Uuid::v7()->toRfc4122(), 0, 8));

        $connection->insert('customer_profile', [
            'id' => Uuid::v7()->toRfc4122(),
            'name' => 'Password Customer',
            'email' => $email,
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'role' => 'ROLE_USER',
            'status' => 'active',
        ]);

        $this->createdEmails[] = $email;

        return $email;
    }

    public function testRedirectReturns302ToGoogleWithExpectedParameters(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->useConfiguredClient();

        $client->request('GET', '/api/v1/auth/google/redirect');

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);

        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        self::assertSame('test-client-id', $query['client_id'] ?? null);
        self::assertSame(self::REDIRECT_URI, $query['redirect_uri'] ?? null);
        self::assertSame('code', $query['response_type'] ?? null);
        self::assertSame('openid email profile', $query['scope'] ?? null);
        self::assertIsString($query['state'] ?? null);
        self::assertNotSame('', $query['state']);
    }

    public function testRedirectReturns503WhenNotConfigured(): void
    {
        $client = static::createClient();

        static::getContainer()->set(
            GoogleOAuthClient::class,
            new GoogleOAuthClient(new MockHttpClient(), '', '', ''),
        );

        $client->request('GET', '/api/v1/auth/google/redirect');

        self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Google sign-in is not configured.', $payload['error'] ?? null);
    }

    public function testCallbackCreatesCustomerAndOpensSession(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->useConfiguredClient();
        $this->createdEmails[] = self::GOOGLE_EMAIL;

        $state = $this->startFlow($client);

        $client->request('GET', '/api/v1/auth/google/callback?code=test-code&state='.urlencode($state));

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertSame(self::STOREFRONT_APP_URL.'/compte', $client->getResponse()->headers->get('Location'));

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $row = $connection->fetchAssociative(
            'SELECT google_id, password_hash FROM customer_profile WHERE lower(email) = lower(:email)',
            ['email' => self::GOOGLE_EMAIL],
        );
        self::assertIsArray($row);
        self::assertSame(self::GOOGLE_SUB, $row['google_id']);
        self::assertNull($row['password_hash']);

        $client->request('GET', '/api/v1/auth/me');
        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(self::GOOGLE_EMAIL, $payload['email'] ?? null);
    }

    public function testSuccessfulCallbackIssuesNoRememberMeCookie(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->useConfiguredClient();
        $this->createdEmails[] = self::GOOGLE_EMAIL;

        $state = $this->startFlow($client);

        $client->request('GET', '/api/v1/auth/google/callback?code=test-code&state='.urlencode($state));

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertSame(
            [],
            $this->issuedRememberMeCookies($client),
            'A Google login is session-only and must not issue a remember-me cookie.',
        );
    }

    public function testSuccessfulCallbackClearsARememberMeCookieFromAPasswordLogin(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->useConfiguredClient();
        $this->createdEmails[] = self::GOOGLE_EMAIL;

        $passwordCustomerEmail = $this->createPasswordCustomer();

        // Customer A signs in with the box ticked: this browser now holds a
        // persistent credential for A.
        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => $passwordCustomerEmail,
            'password' => self::PASSWORD,
            'remember_me' => true,
        ]);
        self::assertResponseIsSuccessful();
        self::assertCount(
            1,
            $this->issuedRememberMeCookies($client),
            'The password login must issue a remember-me cookie for this scenario to be meaningful.',
        );

        // Customer B signs in through Google in the same browser.
        $state = $this->startFlow($client);
        $client->request('GET', '/api/v1/auth/google/callback?code=test-code&state='.urlencode($state));

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);

        // R13: the Google login is session-only, so the credential A left must be
        // cleared instead of signing the next visit in as the previous customer.
        self::assertCount(
            1,
            $this->clearingRememberMeCookies($client),
            'A Google login must clear the remember-me cookie an earlier password login left in the browser.',
        );
        self::assertNull(
            $client->getCookieJar()->get(self::REMEMBER_ME_COOKIE),
            'The clearing cookie must remove the stale credential from the browser.',
        );

        // A cold start carries neither a session cookie nor a remember-me cookie:
        // it is anonymous, never the previous customer.
        $coldStart = $this->coldStart();
        $coldStart->request('GET', '/api/v1/auth/me');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testCallbackCannotBeForcedToIssueARememberMeCookieByARequestParameter(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->useConfiguredClient();
        $this->createdEmails[] = self::GOOGLE_EMAIL;

        $passwordCustomerEmail = $this->createPasswordCustomer();

        // Customer A signs in with the box ticked: this browser now holds a
        // persistent credential for A.
        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => $passwordCustomerEmail,
            'password' => self::PASSWORD,
            'remember_me' => true,
        ]);
        self::assertResponseIsSuccessful();
        self::assertCount(
            1,
            $this->issuedRememberMeCookies($client),
            'The password login must issue a remember-me cookie for this scenario to be meaningful.',
        );

        // Customer B signs in through Google in the same browser, and the callback
        // carries the request parameter Symfony reads when the badge states no
        // preference of its own.
        $state = $this->startFlow($client);
        $client->request('GET', '/api/v1/auth/google/callback?code=test-code&state='.urlencode($state).'&_remember_me=1');

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);

        // R13: the Google login is session-only, so a caller-supplied parameter must
        // never turn it into a login that persists beyond the session.
        self::assertCount(
            0,
            $this->issuedRememberMeCookies($client),
            'A request parameter must not make the Google callback issue a remember-me cookie.',
        );

        // The credential A left must still be forgotten.
        self::assertCount(
            1,
            $this->clearingRememberMeCookies($client),
            'A Google login must clear the remember-me cookie an earlier password login left in the browser.',
        );
        self::assertNull(
            $client->getCookieJar()->get(self::REMEMBER_ME_COOKIE),
            'The clearing cookie must remove the stale credential from the browser.',
        );

        // A cold start carries neither a session cookie nor a remember-me cookie:
        // it is anonymous, never the previous customer nor the Google customer.
        $coldStart = $this->coldStart();
        $coldStart->request('GET', '/api/v1/auth/me');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testCallbackReusesExistingEmailWithoutDuplicating(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->useConfiguredClient();
        $this->createdEmails[] = self::GOOGLE_EMAIL;

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $existingId = Uuid::v7()->toRfc4122();
        $connection->insert('customer_profile', [
            'id' => $existingId,
            'name' => 'Existing Customer',
            'email' => self::GOOGLE_EMAIL,
            'password_hash' => password_hash('ExistingPass!234', PASSWORD_BCRYPT, ['cost' => 4]),
            'role' => 'ROLE_USER',
            'status' => 'active',
        ]);

        $state = $this->startFlow($client);

        $client->request('GET', '/api/v1/auth/google/callback?code=test-code&state='.urlencode($state));

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertSame(self::STOREFRONT_APP_URL.'/compte', $client->getResponse()->headers->get('Location'));

        $count = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM customer_profile WHERE lower(email) = lower(:email)',
            ['email' => self::GOOGLE_EMAIL],
        );
        self::assertSame(1, $count);

        $googleId = $connection->fetchOne(
            'SELECT google_id FROM customer_profile WHERE id = :id',
            ['id' => $existingId],
        );
        self::assertSame(self::GOOGLE_SUB, $googleId);
    }

    public function testCallbackRejectsInvalidAndReplayedState(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->useConfiguredClient();
        $this->createdEmails[] = self::GOOGLE_EMAIL;

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        // Unknown state, no prior /redirect: rejected and nothing is persisted.
        $client->request('GET', '/api/v1/auth/google/callback?code=test-code&state=unknown-state');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $count = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM customer_profile WHERE lower(email) = lower(:email)',
            ['email' => self::GOOGLE_EMAIL],
        );
        self::assertSame(0, $count);

        // A successful callback consumes the single-use state.
        $state = $this->startFlow($client);
        $client->request('GET', '/api/v1/auth/google/callback?code=test-code&state='.urlencode($state));
        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);

        // Replaying the same state is rejected.
        $client->request('GET', '/api/v1/auth/google/callback?code=test-code&state='.urlencode($state));
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testCallbackOnGoogleFailureRedirectsWithoutSession(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->createdEmails[] = self::GOOGLE_EMAIL;

        static::getContainer()->set(GoogleOAuthClient::class, new GoogleOAuthClient(
            new MockHttpClient(new MockResponse(
                '{"error":"invalid_grant"}',
                ['http_code' => 400, 'response_headers' => ['Content-Type' => 'application/json']],
            )),
            'test-client-id',
            'test-client-secret',
            self::REDIRECT_URI,
        ));

        $state = $this->startFlow($client);

        $client->request('GET', '/api/v1/auth/google/callback?code=test-code&state='.urlencode($state));

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertSame(
            self::STOREFRONT_APP_URL.'/connexion?oauth=error',
            $client->getResponse()->headers->get('Location'),
        );

        $client->request('GET', '/api/v1/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testOAuthOnlyCustomerCannotUsePasswordLogin(): void
    {
        $client = static::createClient();
        $this->createdEmails[] = self::GOOGLE_EMAIL;

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $connection->insert('customer_profile', [
            'id' => Uuid::v7()->toRfc4122(),
            'name' => 'OAuth Only Customer',
            'email' => self::GOOGLE_EMAIL,
            'password_hash' => null,
            'google_id' => self::GOOGLE_SUB,
            'role' => 'ROLE_USER',
            'status' => 'active',
        ]);

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => self::GOOGLE_EMAIL,
            'password' => 'Anything!234',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Invalid credentials.', $payload['error'] ?? null);
    }
}
