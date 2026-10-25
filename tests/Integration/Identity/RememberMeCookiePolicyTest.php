<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * R10 (WP1) — the remember-me cookie follows the session cookie policy of its environment:
 * `SameSite=None; Secure` in production, where the storefront and the API answer on separate
 * origins (deploy/Caddyfile), and `SameSite=Lax`, non-secure outside production.
 *
 * The values are read from the cookie the firewall itself emits: a logout clears the remember-me
 * cookie through RememberMeListener -> RememberMeHandlerInterface, and that handler builds the
 * cookie from the composed firewall `remember_me` options (the same options that issue it on a
 * flagged login). This asserts the composed container configuration of a real kernel, never the
 * YAML text.
 *
 * The logout is requested with `POST`, the verb the storefront uses (`logout()` in
 * `Zidane_front_web/src/lib/api/storefront.ts`) and that
 * `RememberMeTest::testLogoutClearsTheRememberMeCookie()` already covers. The firewall's
 * `LogoutListener` matches the logout path, not the method, so the cookie under test is the same.
 */
final class RememberMeCookiePolicyTest extends KernelTestCase
{
    private const COOKIE_NAME = 'REMEMBERME';

    public function testTheRememberMeCookieMirrorsTheSessionCookiePolicyPerEnvironment(): void
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

        $this->assertCookiePolicy('non-production', self::bootKernel(['environment' => 'test']), Cookie::SAMESITE_LAX, false);
    }

    private function assertCookiePolicy(string $label, KernelInterface $kernel, string $expectedSameSite, bool $expectedSecure): void
    {
        $response = $kernel->handle(Request::create('/api/v1/auth/logout', 'POST'));
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $label.' — the firewall must handle the logout path');

        $cookie = $this->rememberMeCookie($response, $label);

        self::assertSame(
            $expectedSameSite,
            $cookie->getSameSite(),
            $label.' — R10: the remember-me SameSite policy must mirror the session cookie',
        );
        self::assertSame(
            $expectedSecure,
            $cookie->isSecure(),
            $label.' — R10: the remember-me Secure flag must mirror the session cookie',
        );
        self::assertTrue($cookie->isHttpOnly(), $label.' — R10: the remember-me cookie is HttpOnly');
        self::assertSame('/', $cookie->getPath(), $label.' — R10: the remember-me cookie is scoped to the whole site');

        $this->assertSessionCookieOptions($label, $kernel, $expectedSameSite, $expectedSecure);
    }

    private function rememberMeCookie(Response $response, string $label): Cookie
    {
        $cookies = array_values(array_filter(
            $response->headers->getCookies(),
            static fn (Cookie $cookie): bool => self::COOKIE_NAME === $cookie->getName(),
        ));

        self::assertCount(1, $cookies, $label.' — the firewall must emit exactly one remember-me cookie');

        return $cookies[0];
    }

    /**
     * R10 makes the remember-me cookie mirror `framework.session.cookie_samesite` / `cookie_secure`,
     * so the expectation is also checked against the session options of the same composed container.
     */
    private function assertSessionCookieOptions(string $label, KernelInterface $kernel, string $expectedSameSite, bool $expectedSecure): void
    {
        $options = $kernel->getContainer()->getParameter('session.storage.options');
        self::assertIsArray($options, $label.' — framework.session must publish its cookie options');

        self::assertSame(
            $expectedSameSite,
            $options['cookie_samesite'] ?? null,
            $label.' — framework.session.cookie_samesite is the policy the remember-me cookie mirrors',
        );
        self::assertSame(
            $expectedSecure,
            $options['cookie_secure'] ?? null,
            $label.' — framework.session.cookie_secure is the policy the remember-me cookie mirrors',
        );
    }
}
