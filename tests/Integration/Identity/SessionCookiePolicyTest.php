<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * R3/R10 — the session cookie keeps one policy per environment, and that policy is the one the
 * application really emits.
 *
 * The values are read from the composed container and from a cookie the session listener builds
 * for a real response (`AbstractSessionListener` copies `session.storage.options` onto the
 * cookie), never from the YAML text.
 *
 * Production ships `SameSite=None; Secure`, the cross-host posture ADR-0011 already assumed. The
 * repository does not record the real hostnames (`.env.prod.example` shows two hosts on the same
 * registrable domain, where `Lax` would be sufficient and stronger), so this test pins the value
 * that is actually delivered rather than an inferred topology; ADR-0013 carries the follow-up.
 * Outside production the cookie stays `SameSite=Lax` and non-secure so
 * `http://localhost:3000` -> `http://localhost:8081` keeps working over plain HTTP (ports are
 * ignored by SameSite).
 */
final class SessionCookiePolicyTest extends KernelTestCase
{
    public function testTheSessionCookiePolicyIsPinnedPerEnvironment(): void
    {
        // A non-debug prod kernel is booted from its own cache directory so the assertion always
        // reflects the configuration on disk (see FreshProductionKernel).
        $production = new FreshProductionKernel('prod', false);
        $production->boot();

        try {
            $this->assertCookiePolicy('production', $production, Cookie::SAMESITE_NONE, true);
        } finally {
            $production->shutdown();
        }

        $this->assertCookiePolicy('non-production (test)', self::bootKernel(['environment' => 'test']), Cookie::SAMESITE_LAX, false);
        $this->assertCookiePolicy('non-production (dev)', self::bootKernel(['environment' => 'dev']), Cookie::SAMESITE_LAX, false);
    }

    private function assertCookiePolicy(string $label, KernelInterface $kernel, string $expectedSameSite, bool $expectedSecure): void
    {
        $options = $kernel->getContainer()->getParameter('session.storage.options');
        self::assertIsArray($options, $label.' — framework.session must publish its cookie options');

        // R2: HttpOnly is environment-independent; it is the whole point of the credential move.
        self::assertTrue($options['cookie_httponly'] ?? false, $label.' — the session cookie must be HttpOnly');
        self::assertSame($expectedSecure, $options['cookie_secure'] ?? null, $label.' — the session Secure flag must follow the environment');
        self::assertSame($expectedSameSite, $options['cookie_samesite'] ?? null, $label.' — the session SameSite policy must follow the environment');
        self::assertSame('/', $options['cookie_path'] ?? null, $label.' — the session cookie is scoped to the whole site');

        // The policy above must be the one written onto a response: a logout clears the session
        // cookie through the same listener that issues it.
        $cookie = $this->clearedSessionCookie($kernel);

        self::assertTrue($cookie->isHttpOnly(), $label.' — the emitted session cookie is HttpOnly');
        self::assertSame($expectedSecure, $cookie->isSecure(), $label.' — the emitted session cookie carries the environment Secure flag');
        self::assertSame($expectedSameSite, $cookie->getSameSite(), $label.' — the emitted session cookie carries the environment SameSite policy');
    }

    /**
     * Logout invalidates the session, and the session listener expires the cookie with the
     * configured attributes. Sending a session cookie makes the request look like a browser that
     * still holds the credential, which is the branch that emits the clearing cookie.
     *
     * The request path is deliberately side-effect free (no database, no mail, no bus dispatch): a
     * prod kernel is booted here, so a logout that started touching real infrastructure would reach
     * the non-test configuration from CI. Keep it that way or move this assertion to a test env.
     */
    private function clearedSessionCookie(KernelInterface $kernel): Cookie
    {
        $request = Request::create(
            '/api/v1/auth/logout',
            'POST',
            [],
            ['PHPSESSID' => 'expired-session-id', 'MOCKSESSID' => 'expired-session-id'],
        );
        $response = $kernel->handle($request);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), 'The firewall must handle the logout path');
        self::assertTrue($request->hasSession(), 'Logout must run against a session');

        // The name comes from the running session, so the assertion follows the storage the
        // environment actually configured instead of guessing PHP's default.
        $sessionName = $request->getSession()->getName();

        $cookies = array_values(array_filter(
            $response->headers->getCookies(),
            static fn (Cookie $cookie): bool => $sessionName === $cookie->getName(),
        ));

        self::assertCount(1, $cookies, sprintf('Logout must expire exactly one session cookie ("%s")', $sessionName));
        self::assertSame('', (string) $cookies[0]->getValue(), 'The expired session cookie must carry no id');

        return $cookies[0];
    }
}
