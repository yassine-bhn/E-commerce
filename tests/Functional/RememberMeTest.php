<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Cookie as ResponseCookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Remember-me behavior: a ticked login survives a browser restart, an unticked
 * login does not, and the remembered credential can always be revoked.
 *
 * A browser restart is simulated by discarding the session cookie: a fresh
 * client is created and only the remember-me cookie is replayed.
 */
final class RememberMeTest extends WebTestCase
{
    /** R3 pins the wire name; keep the literal rather than importing it from production code. */
    private const COOKIE_NAME = 'REMEMBERME';

    /**
     * R11: documented default of REMEMBER_ME_LIFETIME (14 days), used only when the
     * test process does not expose the variable. The tests read the effective value
     * from the booted environment so a hardcoded lifetime fails them.
     */
    private const DEFAULT_REMEMBER_ME_LIFETIME = 1209600;

    private const PASSWORD = 'CorrectHorseBatteryStaple!42';

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

    private function createCustomerUser(?string $email = null, ?string $password = null): CustomerUser
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $id = Uuid::v4()->toRfc4122();
        $email ??= sprintf('remember.me.%s@example.com', substr($id, 0, 8));
        $password ??= self::PASSWORD;

        $connection->insert('customer_profile', [
            'id' => $id,
            'name' => 'Remember Me User',
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]),
            'role' => 'ROLE_USER',
            'status' => 'active',
        ]);

        $this->createdUserIds[] = $id;

        return new CustomerUser($id, $email, null, 'ROLE_USER', 'active');
    }

    private function login(KernelBrowser $client, string $email, string $password, ?bool $rememberMe = null): void
    {
        $payload = ['email' => $email, 'password' => $password];

        if (null !== $rememberMe) {
            $payload['remember_me'] = $rememberMe;
        }

        $client->jsonRequest('POST', '/api/v1/auth/login', $payload);
    }

    /**
     * Returns the remember-me cookie only when it is actually issued: a clearing
     * cookie (empty value) is what the listener sends for unticked or failed
     * logins, and it must not be read as "remembered".
     */
    private function rememberMeCookie(KernelBrowser $client): ?ResponseCookie
    {
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            if (self::COOKIE_NAME === $cookie->getName() && '' !== (string) $cookie->getValue()) {
                return $cookie;
            }
        }

        return null;
    }

    /**
     * Reads the value the browser stored for the remember-me cookie, asserting it
     * was issued and is not a clearing cookie.
     */
    private function storedRememberMeCookieValue(KernelBrowser $client): string
    {
        $cookie = $client->getCookieJar()->get(self::COOKIE_NAME);
        self::assertNotNull($cookie, 'The remember-me cookie must be stored by the browser.');

        $value = (string) $cookie->getValue();
        self::assertNotSame('', $value, 'The remember-me cookie must not be a clearing cookie.');

        return $value;
    }

    /**
     * Starts a new kernel carrying a single raw remember-me cookie: exactly the
     * state of a browser that has no session but still holds the cookie.
     */
    private function clientWithRememberMeCookie(string $value): KernelBrowser
    {
        // A restart means a new kernel: no session storage carried over.
        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->getCookieJar()->set(new Cookie(self::COOKIE_NAME, $value, null, '/', 'localhost'));

        return $client;
    }

    private function restartBrowser(KernelBrowser $client): KernelBrowser
    {
        return $this->clientWithRememberMeCookie($this->storedRememberMeCookieValue($client));
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
     * R6/R8: a rejected credential answers the firewall's JSON 401, never an HTML
     * login page.
     */
    private static function assertJsonUnauthorized(KernelBrowser $client): void
    {
        $response = $client->getResponse();

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode(), 'The request must be unauthenticated.');
        self::assertStringContainsString(
            'application/json',
            (string) $response->headers->get('Content-Type'),
            'A JSON caller must receive a JSON error, not an HTML page.',
        );
        self::assertNull(
            $response->headers->get('Location'),
            'A rejected credential must never be redirected to an HTML login page.',
        );
    }

    /**
     * R11: lifetime resolved by the booted application, read after the kernel is
     * running, so pinning the literal here cannot mask a lifetime hardcoded in
     * security.yaml. The 14-day fallback only covers a process that exports no
     * REMEMBER_ME_LIFETIME at all.
     */
    private static function configuredRememberMeLifetime(): int
    {
        return (int) (
            $_SERVER['REMEMBER_ME_LIFETIME']
            ?? $_ENV['REMEMBER_ME_LIFETIME']
            ?? self::DEFAULT_REMEMBER_ME_LIFETIME
        );
    }

    public function testFlaggedLoginIssuesAPersistentHttpOnlyCookie(): void
    {
        $client = static::createClient();
        $user = $this->createCustomerUser();

        $this->login($client, $user->getEmail(), self::PASSWORD, true);

        self::assertResponseIsSuccessful();

        $cookie = $this->rememberMeCookie($client);
        self::assertInstanceOf(ResponseCookie::class, $cookie, 'A flagged login must set the remember-me cookie.');
        self::assertGreaterThan(time() + 86400, $cookie->getExpiresTime(), 'The remember-me cookie must outlive the browser session.');
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('/', $cookie->getPath());
        self::assertSame('lax', $cookie->getSameSite());
    }

    public function testIssuedCookieLifetimeMatchesTheConfiguredRememberMeLifetime(): void
    {
        $client = static::createClient();
        $user = $this->createCustomerUser();

        // After the kernel has booted, this is the lifetime the firewall resolved
        // from the environment — not a copy of the documented default.
        $lifetime = self::configuredRememberMeLifetime();

        // The expiry is computed with time() while the response is built, so it
        // must fall between "now + lifetime" measured before and after the call.
        $beforeLogin = time();
        $this->login($client, $user->getEmail(), self::PASSWORD, true);
        $afterLogin = time();

        self::assertResponseIsSuccessful();

        $cookie = $this->rememberMeCookie($client);
        self::assertInstanceOf(ResponseCookie::class, $cookie);

        self::assertGreaterThanOrEqual(
            $beforeLogin + $lifetime,
            $cookie->getExpiresTime(),
            'The remember-me cookie must live for the configured REMEMBER_ME_LIFETIME.',
        );
        self::assertLessThanOrEqual(
            $afterLogin + $lifetime,
            $cookie->getExpiresTime(),
            'The remember-me cookie must live for the configured REMEMBER_ME_LIFETIME.',
        );
    }

    /**
     * R2: the flag is either absent, `true` or `false`; any other present value is
     * a client error instead of being coerced to a boolean.
     */
    #[DataProvider('nonBooleanRememberMeValues')]
    public function testNonBooleanRememberMeIsRejectedWith400(mixed $rememberMe): void
    {
        $client = static::createClient();
        $user = $this->createCustomerUser();

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => $user->getEmail(),
            'password' => self::PASSWORD,
            'remember_me' => $rememberMe,
        ]);

        self::assertResponseStatusCodeSame(
            Response::HTTP_BAD_REQUEST,
            'A remember_me value that is neither a boolean nor absent must be rejected, not coerced.',
        );
        self::assertNull(
            $this->rememberMeCookie($client),
            'A rejected login must not issue a remember-me cookie.',
        );
    }

    /**
     * R2/R4: an absent or explicitly false flag is the unticked checkbox and keeps
     * the login session-only. Google logins are covered by
     * GoogleOAuthTest::testSuccessfulCallbackIssuesNoRememberMeCookie().
     */
    #[DataProvider('untickedRememberMeValues')]
    public function testUntickedLoginIssuesNoRememberMeCookie(?bool $rememberMe): void
    {
        $client = static::createClient();
        $user = $this->createCustomerUser();

        $this->login($client, $user->getEmail(), self::PASSWORD, $rememberMe);

        self::assertResponseIsSuccessful();
        self::assertNull($this->rememberMeCookie($client), 'An unticked login must not issue a remember-me cookie.');
    }

    public function testUnflaggedLoginClearsAPreviouslyIssuedRememberMeCookie(): void
    {
        $client = static::createClient();
        $user = $this->createCustomerUser();

        $this->login($client, $user->getEmail(), self::PASSWORD, true);
        self::assertNotNull($this->rememberMeCookie($client));

        $this->login($client, $user->getEmail(), self::PASSWORD, false);

        self::assertResponseIsSuccessful();
        self::assertNull($client->getCookieJar()->get(self::COOKIE_NAME));
    }

    public function testRememberMeCookieAloneAuthenticatesAfterRestart(): void
    {
        $client = static::createClient();
        $user = $this->createCustomerUser();

        $this->login($client, $user->getEmail(), self::PASSWORD, true);
        self::assertResponseIsSuccessful();

        $restarted = $this->restartBrowser($client);
        $restarted->request('GET', '/api/v1/auth/me');

        self::assertResponseIsSuccessful();
        $payload = self::jsonBody($restarted);
        self::assertSame($user->getId(), $payload['id'] ?? null);
        self::assertSame($user->getEmail(), $payload['email'] ?? null);
    }

    public function testCookieOnlyLoginStartsASession(): void
    {
        $client = static::createClient();
        $user = $this->createCustomerUser();

        $this->login($client, $user->getEmail(), self::PASSWORD, true);
        self::assertResponseIsSuccessful();

        $restarted = $this->restartBrowser($client);
        $restarted->request('GET', '/api/v1/auth/me');
        self::assertResponseIsSuccessful();

        // The auto-login must open a session of its own: with the remember-me
        // cookie gone, the session cookie alone keeps the customer signed in.
        $restarted->getCookieJar()->expire(self::COOKIE_NAME);
        self::assertNull($restarted->getCookieJar()->get(self::COOKIE_NAME));

        $restarted->request('GET', '/api/v1/auth/me');

        self::assertResponseIsSuccessful();
        self::assertSame($user->getId(), self::jsonBody($restarted)['id'] ?? null);
    }

    public function testAutoLoginReissuesTheCookieWithoutCancellingIt(): void
    {
        $client = static::createClient();
        $user = $this->createCustomerUser();

        $this->login($client, $user->getEmail(), self::PASSWORD, true);
        self::assertResponseIsSuccessful();

        // R15 is about the credential, so keep the Cookie object the login emitted
        // and replay its value: no hand-parsing of the vendor wire format.
        $consumed = $this->rememberMeCookie($client);
        self::assertInstanceOf(ResponseCookie::class, $consumed, 'The login must issue the remember-me cookie.');

        $restarted = $this->clientWithRememberMeCookie((string) $consumed->getValue());
        $restarted->request('GET', '/api/v1/auth/me');
        self::assertResponseIsSuccessful();

        // R15: the auto-login never cancels the credential, it re-issues it.
        $reissued = $this->rememberMeCookie($restarted);
        self::assertInstanceOf(
            ResponseCookie::class,
            $reissued,
            'The cookie-only login must re-issue the remember-me cookie instead of cancelling it.',
        );

        // Rolling expiry: the re-issued cookie may not shorten the remembered
        // window, so a second and every later restart stays authenticated.
        self::assertGreaterThanOrEqual(
            $consumed->getExpiresTime(),
            $reissued->getExpiresTime(),
            'The re-issued remember-me cookie must expire no earlier than the one it consumed.',
        );
    }

    public function testSecondRestartStillAuthenticatesAndKeepsTheCookie(): void
    {
        $client = static::createClient();
        $user = $this->createCustomerUser();

        $this->login($client, $user->getEmail(), self::PASSWORD, true);
        self::assertResponseIsSuccessful();

        $consumed = $this->rememberMeCookie($client);
        self::assertInstanceOf(ResponseCookie::class, $consumed, 'The login must issue the remember-me cookie.');

        $firstRestart = $this->clientWithRememberMeCookie((string) $consumed->getValue());
        $firstRestart->request('GET', '/api/v1/auth/me');
        self::assertResponseIsSuccessful();

        $firstReissued = $this->rememberMeCookie($firstRestart);
        self::assertInstanceOf(
            ResponseCookie::class,
            $firstReissued,
            'The first restart must re-issue the remember-me cookie.',
        );

        $secondRestart = $this->clientWithRememberMeCookie((string) $firstReissued->getValue());
        $secondRestart->request('GET', '/api/v1/auth/me');

        self::assertResponseIsSuccessful();
        self::assertSame(
            $user->getId(),
            self::jsonBody($secondRestart)['id'] ?? null,
            'The second restart must sign the same customer in.',
        );

        $secondReissued = $this->rememberMeCookie($secondRestart);
        self::assertInstanceOf(
            ResponseCookie::class,
            $secondReissued,
            'The second restart must keep the remember-me cookie alive.',
        );
        self::assertGreaterThanOrEqual(
            $firstReissued->getExpiresTime(),
            $secondReissued->getExpiresTime(),
            'The remember-me cookie must not shrink on a second restart.',
        );
    }

    public function testWithoutAnyCookieTheVisitorStaysAnonymous(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/auth/me');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testFailedFlaggedLoginIssuesNoRememberMeCookie(): void
    {
        $client = static::createClient();
        $user = $this->createCustomerUser();

        $this->login($client, $user->getEmail(), 'WrongPassword999!', true);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertNull($this->rememberMeCookie($client));
    }

    public function testTamperedCookieIsRejectedWithJson401(): void
    {
        $client = static::createClient();
        $user = $this->createCustomerUser();

        $this->login($client, $user->getEmail(), self::PASSWORD, true);
        $value = $this->storedRememberMeCookieValue($client);

        $tampered = substr($value, 0, -2).(str_ends_with($value, 'aa') ? 'bb' : 'aa');

        $restarted = $this->clientWithRememberMeCookie($tampered);
        $restarted->request('GET', '/api/v1/auth/me');

        self::assertJsonUnauthorized($restarted);
    }

    public function testTruncatedCookieIsRejectedWithJson401(): void
    {
        $client = static::createClient();
        $user = $this->createCustomerUser();

        $this->login($client, $user->getEmail(), self::PASSWORD, true);
        $value = $this->storedRememberMeCookieValue($client);

        $truncated = substr($value, 0, intdiv(\strlen($value), 2));

        $restarted = $this->clientWithRememberMeCookie($truncated);
        $restarted->request('GET', '/api/v1/auth/me');

        self::assertJsonUnauthorized($restarted);
    }

    public function testMalformedExpirySegmentIsRejectedWithJson401(): void
    {
        // Structurally plausible cookie: the user segment is base64 like the
        // signature handler emits, but the expiry is not a timestamp. The
        // decoder must reject the credential; it must never crash on it.
        $malformed = 'Class:'.strtr(base64_encode('user@example.com'), '+/=', '-_~').':not-a-number:abc';

        $client = $this->clientWithRememberMeCookie($malformed);
        $client->request('GET', '/api/v1/auth/me');

        self::assertJsonUnauthorized($client);
    }

    public function testExpiredCookieIsRejectedWithJson401(): void
    {
        // R8 "expired": a structurally valid cookie whose signed expiry is a past
        // timestamp. SignatureHasher checks the expiry before the HMAC, so the
        // payload needs no valid signature — the credential must be refused with
        // the firewall's JSON 401, never a 5xx and never a redirect.
        $expired = ':dXNlcg~~:1000000000:whatever';

        $client = $this->clientWithRememberMeCookie($expired);
        $client->request('GET', '/api/v1/auth/me');

        self::assertJsonUnauthorized($client);
    }

    public function testInvalidCookieOnARoleUserEndpointReturnsTheEntryPointJson401(): void
    {
        $client = $this->clientWithRememberMeCookie('not-a-remember-me-cookie');

        $client->request('GET', '/api/v1/storefront/customer/profile');

        self::assertJsonUnauthorized($client);
        self::assertNull(
            $client->getResponse()->headers->get('Location'),
            'A JSON caller must never be redirected to an HTML login page.',
        );

        self::assertSame('Authentication required.', self::jsonBody($client)['error'] ?? null);
    }

    public function testLogoutClearsTheRememberMeCookie(): void
    {
        $client = static::createClient();
        $user = $this->createCustomerUser();

        $this->login($client, $user->getEmail(), self::PASSWORD, true);
        self::assertNotNull($this->rememberMeCookie($client));

        $client->request('POST', '/api/v1/auth/logout');

        self::assertResponseIsSuccessful();
        self::assertNull($client->getCookieJar()->get(self::COOKIE_NAME));
    }

    /** @return iterable<string, array{mixed}> */
    public static function nonBooleanRememberMeValues(): iterable
    {
        yield 'truthy string "yes"' => ['yes'];
        yield 'integer 1' => [1];
        yield 'array' => [[]];
    }

    /** @return iterable<string, array{?bool}> */
    public static function untickedRememberMeValues(): iterable
    {
        yield 'remember_me absent from the request' => [null];
        yield 'remember_me explicitly false' => [false];
    }
}
