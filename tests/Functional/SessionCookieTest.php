<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Cookie as ResponseCookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * R1/R2/R8/R10 — the session credential exists only as an HttpOnly cookie.
 *
 * These tests read the Set-Cookie header the application really emits, not the YAML: the
 * session listener copies `framework.session.cookie_*` onto the response cookie, so dropping
 * `cookie_httponly` (or reusing a session id that was handed to the browser some other way)
 * turns them red.
 *
 * The session cookie name is resolved from the running session instead of being hardcoded:
 * the test environment swaps the session storage for a mock, which names its cookie
 * `MOCKSESSID` while production uses PHP's own name.
 */
final class SessionCookieTest extends WebTestCase
{
    private const PASSWORD = 'CorrectHorseBatteryStaple!42';

    /** Remember-me is a separate credential with its own suite (`RememberMeTest`). */
    private const REMEMBER_ME_COOKIE = 'REMEMBERME';

    /** PHP's own session cookie name, and the one the test environment's mock storage uses. */
    private const SESSION_COOKIE_NAMES = ['PHPSESSID', 'MOCKSESSID'];

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

    public function testLoginAnswersWithAnHttpOnlySessionCookieAndNoToken(): void
    {
        $client = static::createClient();
        $email = $this->createCustomerUser();

        $this->login($client, $email);

        self::assertResponseIsSuccessful();

        $body = self::jsonBody($client);

        // R1: the response body carries identity, never a credential a client could store.
        self::assertSame(['email', 'id', 'roles'], $this->sortedKeys($body), 'The login body must expose identity only.');
        foreach (array_keys($body) as $key) {
            self::assertDoesNotMatchRegularExpression(
                '/(token|session|jwt|bearer|secret|password)/i',
                (string) $key,
                'The login body must not expose a credential field.',
            );
        }

        $sessionCookie = $this->sessionCookie($client);
        $rawHeader = $this->rawSetCookie($client, $sessionCookie->getName());

        // R2: HttpOnly is what makes the credential unreadable from JavaScript.
        self::assertTrue($sessionCookie->isHttpOnly(), 'The session cookie must be HttpOnly.');
        self::assertStringContainsStringIgnoringCase('httponly', $rawHeader, 'The emitted Set-Cookie must carry HttpOnly.');
        self::assertStringNotContainsString((string) $sessionCookie->getValue(), (string) $client->getResponse()->getContent(), 'The session id must never be echoed in the body.');
        self::assertSame('/', $sessionCookie->getPath(), 'The session cookie must be scoped to the whole site.');
        self::assertSame('lax', $sessionCookie->getSameSite(), 'Outside production the session cookie is SameSite=Lax.');
        self::assertFalse($sessionCookie->isSecure(), 'Outside production the session cookie must stay usable over plain HTTP.');
    }

    public function testTheSessionCookieAloneAuthenticatesTheNextRequest(): void
    {
        $client = static::createClient();
        $email = $this->createCustomerUser();

        $this->login($client, $email);
        self::assertResponseIsSuccessful();

        // R9: the browser replays the cookie; no Authorization header is ever involved.
        $client->request('GET', '/api/v1/auth/me');

        self::assertResponseIsSuccessful();

        $body = self::jsonBody($client);
        self::assertSame($email, $body['email'] ?? null);
        foreach (array_keys($body) as $key) {
            self::assertDoesNotMatchRegularExpression(
                '/(token|session|jwt|bearer|secret)/i',
                (string) $key,
                'The session probe must not disclose a credential field.',
            );
        }
    }

    public function testLogoutExpiresTheSessionCookieAndRevokesTheSession(): void
    {
        $client = static::createClient();
        $email = $this->createCustomerUser();

        $this->login($client, $email);
        self::assertResponseIsSuccessful();

        $sessionCookie = $this->sessionCookie($client);
        $sessionCookieName = $sessionCookie->getName();
        $sessionId = (string) $sessionCookie->getValue();
        self::assertNotSame('', $sessionId, 'A successful login must hand out a session id before logout is exercised.');

        $client->jsonRequest('POST', '/api/v1/auth/logout');

        self::assertResponseIsSuccessful();

        // R8: the credential is expired (empty value, in the past), not merely forgotten by the UI.
        $cleared = array_values(array_filter(
            $client->getResponse()->headers->getCookies(),
            static fn (ResponseCookie $cookie): bool => $sessionCookieName === $cookie->getName(),
        ));
        self::assertCount(1, $cleared, 'Logout must expire the session cookie.');
        self::assertSame('', (string) $cleared[0]->getValue(), 'The expired session cookie must carry no id.');
        self::assertLessThanOrEqual(time(), $cleared[0]->getExpiresTime(), 'The expired session cookie must be in the past.');

        // R8 also covers the second credential: logout clears remember-me even when the login did not use it.
        $rememberMe = array_values(array_filter(
            $client->getResponse()->headers->getCookies(),
            static fn (ResponseCookie $cookie): bool => self::REMEMBER_ME_COOKIE === $cookie->getName(),
        ));
        self::assertCount(1, $rememberMe, 'Logout must clear the remember-me cookie.');
        self::assertSame('', (string) $rememberMe[0]->getValue(), 'The cleared remember-me cookie must carry no value.');

        // The cookie jar follows the expiry, so the next call is unauthenticated.
        $client->request('GET', '/api/v1/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        // Replaying the pre-logout id proves the session itself was destroyed server-side: clearing
        // the cookie alone would let a stolen id keep working.
        $client->getCookieJar()->set(new Cookie($sessionCookieName, $sessionId, null, '/', 'localhost'));
        $client->request('GET', '/api/v1/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testRegisterAnswersWithIdentityOnlyAndOpensNoSession(): void
    {
        $client = static::createClient();

        $email = sprintf('session.register.%s@example.com', substr(Uuid::v4()->toRfc4122(), 0, 8));

        $client->jsonRequest('POST', '/api/v1/auth/register', [
            'name' => 'Session Register User',
            'email' => $email,
            'password' => self::PASSWORD,
            'phone' => '+21600000000',
            'type' => 'particulier',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        // R1: registration returns the new identity, never a credential the client could store.
        self::assertSame(['email', 'id', 'roles', 'status'], $this->sortedKeys(self::jsonBody($client)));

        // Registering must not open a session. The test environment swaps the session storage for a
        // mock, so both cookie names are checked: asserting only PHPSESSID would be vacuous here.
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            self::assertNotContains(
                $cookie->getName(),
                self::SESSION_COOKIE_NAMES,
                'Registering must not start a session; the storefront signs in explicitly afterwards.',
            );
        }
        foreach (self::SESSION_COOKIE_NAMES as $sessionCookieName) {
            self::assertNull($client->getCookieJar()->get($sessionCookieName), 'Registering must not store a session id.');
        }

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $id = $connection->fetchOne('SELECT id FROM customer_profile WHERE email = :email', ['email' => $email]);
        self::assertIsString($id);
        $this->createdUserIds[] = $id;
    }

    private function login(KernelBrowser $client, string $email): void
    {
        $client->jsonRequest('POST', '/api/v1/auth/login', ['email' => $email, 'password' => self::PASSWORD]);
    }

    /**
     * The session cookie of the last response: every Set-Cookie that is not the remember-me one.
     */
    private function sessionCookie(KernelBrowser $client): ResponseCookie
    {
        $cookies = array_values(array_filter(
            $client->getResponse()->headers->getCookies(),
            static fn (ResponseCookie $cookie): bool => self::REMEMBER_ME_COOKIE !== $cookie->getName(),
        ));

        self::assertCount(1, $cookies, 'A successful login must set exactly one session cookie besides remember-me.');

        return $cookies[0];
    }

    /**
     * The literal header, so the assertion cannot be satisfied by a Cookie object built in the test.
     */
    private function rawSetCookie(KernelBrowser $client, string $cookieName): string
    {
        foreach ($client->getResponse()->headers->all('Set-Cookie') as $header) {
            if (null !== $header && str_starts_with($header, $cookieName.'=')) {
                return $header;
            }
        }

        self::fail(sprintf('No Set-Cookie header was emitted for "%s".', $cookieName));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function jsonBody(KernelBrowser $client): array
    {
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @return list<string>
     */
    private function sortedKeys(array $body): array
    {
        $keys = array_map(strval(...), array_keys($body));
        sort($keys);

        return $keys;
    }

    private function createCustomerUser(): string
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $id = Uuid::v4()->toRfc4122();
        $email = sprintf('session.cookie.%s@example.com', substr($id, 0, 8));

        $connection->insert('customer_profile', [
            'id' => $id,
            'name' => 'Session Cookie User',
            'email' => $email,
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'role' => 'ROLE_USER',
            'status' => 'active',
        ]);

        $this->createdUserIds[] = $id;

        return $email;
    }
}
