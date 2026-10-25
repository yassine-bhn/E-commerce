<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Behavior 5 (spec-run-9/spec.md R5, R6, R7, R14, R15, R16, R19) — a visitor who has not signed in
 * gets the configured WhatsApp contact as a plain public read: the digits-only number, the prefilled
 * default message and the https://wa.me/ link built from them, with no cookie, no login redirect and
 * no rate limiter in the way.
 */
final class WhatsAppContactTest extends WebTestCase
{
    /**
     * R4 — the failure-path tests poison the configured number to prove the failure behaviour. Both
     * superglobals are captured before every test and written back unconditionally after it, so a
     * poisoned value cannot leak into a later test and no branch is needed to undo it.
     */
    private string $previousEnvPhoneNumber = '';
    private string $previousServerPhoneNumber = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousEnvPhoneNumber = (string) ($_ENV['WHATSAPP_PHONE_NUMBER'] ?? '');
        $this->previousServerPhoneNumber = (string) ($_SERVER['WHATSAPP_PHONE_NUMBER'] ?? '');
    }

    protected function tearDown(): void
    {
        $_ENV['WHATSAPP_PHONE_NUMBER'] = $this->previousEnvPhoneNumber;
        $_SERVER['WHATSAPP_PHONE_NUMBER'] = $this->previousServerPhoneNumber;

        parent::tearDown();
    }

    public function testAnonymousVisitorReceivesTheConfiguredWhatsAppChatLink(): void
    {
        $configuredPhoneNumber = ltrim(self::configuredValue('WHATSAPP_PHONE_NUMBER'), '+');
        $configuredMessage = self::configuredValue('WHATSAPP_DEFAULT_MESSAGE');

        $client = static::createClient();
        $client->request('GET', '/api/v1/storefront/contact/whatsapp');

        // R5/R15 — public read: a login challenge, a 404/405 or a 429 fails here.
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        /** @var array{phoneNumber: string, message: string, chatUrl: string} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // R6 — exactly the three contract keys, and nothing else.
        self::assertEqualsCanonicalizing(['phoneNumber', 'message', 'chatUrl'], array_keys($body));

        // R7 — digits only (no "+", no spaces) and taken from the configured number.
        self::assertMatchesRegularExpression('/\A[0-9]+\z/', $body['phoneNumber']);
        self::assertSame($configuredPhoneNumber, $body['phoneNumber']);

        // R8 — the effective message is the configured default, echoed verbatim.
        self::assertSame($configuredMessage, $body['message']);

        // R9/R10 — the link is regenerated from configuration and percent-encodes the message.
        self::assertSame(
            'https://wa.me/'.$configuredPhoneNumber.'?text='.rawurlencode($configuredMessage),
            $body['chatUrl'],
        );

        // R13 — the destination is always the official WhatsApp host over HTTPS.
        $chatUrlParts = parse_url((string) $body['chatUrl']);
        self::assertIsArray($chatUrlParts);
        self::assertSame('https', $chatUrlParts['scheme'] ?? null);
        self::assertSame('wa.me', $chatUrlParts['host'] ?? null);

        // R14 — the answered body carries no application secret.
        self::assertStringNotContainsString(self::configuredValue('APP_SECRET'), (string) $client->getResponse()->getContent());

        // R15/R16 — no session cookie and no redirect on this stateless read.
        self::assertResponseNotHasHeader('Set-Cookie');
        self::assertResponseNotHasHeader('Location');
    }

    /**
     * Behavior 6 (spec-run-9/spec.md R8, R10, R11) — the optional `message` query parameter replaces
     * the configured default when it carries content; the effective message is echoed byte-for-byte
     * in the `message` field and in the percent-decoded `text=` parameter of `chatUrl`.
     */
    public function testMessageParameterOverridesTheDefault(): void
    {
        $client = static::createClient();

        $frenchMessage = 'Bonjour, je souhaite un devis';
        $frenchBody = self::contactPayload($client, '/api/v1/storefront/contact/whatsapp?message='.rawurlencode($frenchMessage));
        self::assertSame($frenchMessage, $frenchBody['message']);
        self::assertChatUrlCarriesMessage($frenchMessage, $frenchBody['chatUrl']);

        // R10 — multi-byte text crosses the boundary untouched: the same request as
        // `?message=مرحبا%2C%20أريد%20معلومات`, fully percent-encoded so the bytes are unambiguous.
        $arabicMessage = 'مرحبا, أريد معلومات';
        $arabicBody = self::contactPayload($client, '/api/v1/storefront/contact/whatsapp?message='.rawurlencode($arabicMessage));
        self::assertSame($arabicMessage, $arabicBody['message']);
        self::assertChatUrlCarriesMessage($arabicMessage, $arabicBody['chatUrl']);
    }

    /**
     * Behavior 6 (spec-run-9/spec.md R11) — absence, an empty value and ASCII spaces only are not
     * content: the configured default wins and the response stays 200.
     */
    #[DataProvider('blankMessageProvider')]
    public function testBlankMessageFallsBackToTheDefault(string $uri): void
    {
        $body = self::contactPayload(static::createClient(), $uri);

        self::assertSame(self::configuredValue('WHATSAPP_DEFAULT_MESSAGE'), $body['message']);
    }

    /** @return iterable<string, array{string}> */
    public static function blankMessageProvider(): iterable
    {
        yield 'absent' => ['/api/v1/storefront/contact/whatsapp'];
        yield 'empty' => ['/api/v1/storefront/contact/whatsapp?message='];
        yield 'spaces only' => ['/api/v1/storefront/contact/whatsapp?message=%20%20%20'];
    }

    /**
     * Behavior 6 (spec-run-9/spec.md R10) — reserved characters in a contextual message stay inside a
     * single `text=` parameter, so `&` or `#` cannot split or truncate the generated link.
     */
    public function testReservedCharactersCannotBreakTheChatUrl(): void
    {
        $configuredPhoneNumber = ltrim(self::configuredValue('WHATSAPP_PHONE_NUMBER'), '+');
        $hostileMessage = 'a&b=c#d';

        $body = self::contactPayload(
            static::createClient(),
            '/api/v1/storefront/contact/whatsapp?message='.rawurlencode($hostileMessage),
        );

        self::assertSame($hostileMessage, $body['message']);
        self::assertSame(
            'https://wa.me/'.$configuredPhoneNumber.'?text=a%26b%3Dc%23d',
            $body['chatUrl'],
            'R10 — every reserved character is percent-encoded before it reaches the URL.',
        );
    }

    /**
     * Behavior 7 (spec-run-9/spec.md R12) — an array where a single string is expected is invalid
     * input, not an absent one: the caller gets a 400 instead of a silent default.
     */
    public function testRejectsANonScalarMessageWithA400(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/storefront/contact/whatsapp?message[]=Bonjour');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        /** @var array<string, string> $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['error'], array_keys($body));
    }

    /**
     * Behavior 7 (spec-run-9/spec.md R12, R14) — a contextual message that cannot be used as-is is a
     * client error: HTTP 400 with the project's {"error": …} body and nothing else, so no link is
     * ever built from over-long, control-character-laden or undecodable input.
     */
    #[DataProvider('invalidContextualMessageProvider')]
    public function testRejectsAnInvalidContextualMessageWithA400AndOnlyAnErrorKey(string $invalidMessage): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/storefront/contact/whatsapp?message='.rawurlencode($invalidMessage));

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        /** @var array<string, string> $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // R12 — the rejection is the documented error body: one key, no echo of the rejected input
        // and no chatUrl the caller could mistake for a usable link.
        self::assertSame(['error'], array_keys($body));
        self::assertNotSame('', $body['error']);
        self::assertStringNotContainsString('https://wa.me/', (string) $client->getResponse()->getContent());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidContextualMessageProvider(): iterable
    {
        yield 'over the 200 code point cap' => [str_repeat('é', 201)];
        yield 'newline' => ["line1\nline2"];
        yield 'trailing newline' => ["Hello\n"];
        yield 'newline only' => ["\n"];
        yield 'tab' => ["tab\there"];
        yield 'null byte only' => ["\x00"];
        yield 'invalid utf-8' => ["\xFF"];
    }

    /**
     * Behavior 8 (spec-run-9/spec.md R13, R21) — no request input can move the destination: the
     * phone, number, url, host and scheme parameters are ignored, the digits stay the configured
     * ones and every chatUrl still points at https://wa.me.
     */
    public function testNoRequestParameterCanChangeTheDestination(): void
    {
        $configuredPhoneNumber = ltrim(self::configuredValue('WHATSAPP_PHONE_NUMBER'), '+');
        $client = static::createClient();

        $client->request('GET', '/api/v1/storefront/contact/whatsapp?phone=99999999&number=11111111'
            .'&url='.rawurlencode('https://evil.example/hijack').'&host=evil.example&scheme=http&message=Bonjour');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        /** @var array{phoneNumber: string, message: string, chatUrl: string} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($configuredPhoneNumber, $body['phoneNumber'], 'R13 — the digits come from configuration, not from the query string.');
        self::assertSame('Bonjour', $body['message']);
        self::assertSame(
            'https://wa.me/'.$configuredPhoneNumber.'?text=Bonjour',
            $body['chatUrl'],
            'R13/R21 — the whole link is regenerated server-side from the configured number.',
        );

        $chatUrlParts = parse_url($body['chatUrl']);
        self::assertIsArray($chatUrlParts);
        self::assertSame('https', $chatUrlParts['scheme'] ?? null);
        self::assertSame('wa.me', $chatUrlParts['host'] ?? null);
    }

    /**
     * Behavior 9 (spec-run-9/spec.md R4) — an unparseable configured number is a configuration
     * error: the contact read answers a server error instead of a payload, builds no link from the
     * value and fails the same way on every attempt.
     */
    public function testAnUnparseableConfiguredNumberAnswersWithAServerErrorAndNoChatLink(): void
    {
        // Both superglobals are poisoned: EnvVarProcessor prefers $_ENV, and a stale good value in
        // $_SERVER would make this test pass for the wrong reason.
        $_ENV['WHATSAPP_PHONE_NUMBER'] = '216XXXXXXXX';
        $_SERVER['WHATSAPP_PHONE_NUMBER'] = '216XXXXXXXX';

        $client = static::createClient();
        $client->request('GET', '/api/v1/storefront/contact/whatsapp');

        // R4 — the configuration error surfaces as a server error, not as a 404/401/429 that a
        // missing or protected route would also produce, and it fails the same way every time.
        self::assertResponseStatusCodeSame(Response::HTTP_INTERNAL_SERVER_ERROR);
        $firstAttempt = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('https://wa.me/', $firstAttempt, 'R4 — no chat link is built from the unparseable value.');

        $client->request('GET', '/api/v1/storefront/contact/whatsapp');
        self::assertResponseStatusCodeSame(Response::HTTP_INTERNAL_SERVER_ERROR, 'R4 — the failure is deterministic.');
    }

    /**
     * Behavior 9 (spec-run-9/spec.md R14) — the server error raised by an unparseable configured
     * number does not disclose the application secret in its body.
     */
    public function testAnUnparseableConfiguredNumberDoesNotDiscloseTheApplicationSecret(): void
    {
        // Both superglobals are poisoned: EnvVarProcessor prefers $_ENV, and a stale good value in
        // $_SERVER would make this test pass for the wrong reason.
        $_ENV['WHATSAPP_PHONE_NUMBER'] = '216XXXXXXXX';
        $_SERVER['WHATSAPP_PHONE_NUMBER'] = '216XXXXXXXX';

        $client = static::createClient();
        $client->request('GET', '/api/v1/storefront/contact/whatsapp');

        self::assertResponseStatusCodeSame(Response::HTTP_INTERNAL_SERVER_ERROR);
        self::assertStringNotContainsString(
            self::configuredValue('APP_SECRET'),
            (string) $client->getResponse()->getContent(),
            'R14 — the failure does not disclose application secrets.',
        );
    }

    /**
     * Behavior 9 (spec-run-9/spec.md R18) — the reference read keeps taking its contact from
     * platform_setting, so an unparseable WHATSAPP_* value leaves contact.whatsapp untouched.
     */
    public function testAnUnparseableConfiguredNumberLeavesTheReferenceContactOnPlatformSetting(): void
    {
        // Both superglobals are poisoned: EnvVarProcessor prefers $_ENV, and a stale good value in
        // $_SERVER would make this test pass for the wrong reason.
        $_ENV['WHATSAPP_PHONE_NUMBER'] = '216XXXXXXXX';
        $_SERVER['WHATSAPP_PHONE_NUMBER'] = '216XXXXXXXX';

        $client = static::createClient();

        // R18 — the reference read keeps answering from platform_setting, not from WHATSAPP_*.
        $client->request('GET', '/api/v1/storefront/reference');
        self::assertResponseStatusCodeSame(Response::HTTP_OK, 'R18 — unrelated storefront reads are unaffected.');

        /** @var array{contact: array{whatsapp: string}} $reference */
        $reference = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        self::assertSame(
            (string) $connection->fetchOne('SELECT contact_whatsapp FROM platform_setting WHERE id = 1'),
            $reference['contact']['whatsapp'],
            'R18 — platform_setting still feeds contact.whatsapp, independently of the broken env value.',
        );
    }

    /**
     * R2/R14 — the expected values are read from the test environment, never hardcoded, so a
     * configuration-only change (number or message) keeps this contract honest.
     */
    private static function configuredValue(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        self::assertIsString($value, sprintf('R1 — %s must be declared for the test environment.', $name));

        return $value;
    }

    /**
     * One anonymous GET through the real kernel, asserting the public-read status before decoding.
     * The caller owns the client so one test can issue several requests through a single kernel.
     *
     * @return array{phoneNumber: string, message: string, chatUrl: string}
     */
    private static function contactPayload(KernelBrowser $client, string $uri): array
    {
        $client->request('GET', $uri);

        // R11 — a missing, empty or whitespace-only parameter is not a client error: always 200.
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        /** @var array{phoneNumber: string, message: string, chatUrl: string} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $body;
    }

    /**
     * R8/R10/R13 — the `chatUrl` points at the official host over HTTPS and its `text=` parameter
     * decodes back to exactly the message echoed in the JSON body.
     */
    private static function assertChatUrlCarriesMessage(string $expectedMessage, string $chatUrl): void
    {
        $chatUrlParts = parse_url($chatUrl);
        self::assertIsArray($chatUrlParts);
        self::assertSame('https', $chatUrlParts['scheme'] ?? null);
        self::assertSame('wa.me', $chatUrlParts['host'] ?? null);

        $query = $chatUrlParts['query'] ?? '';
        self::assertStringStartsWith('text=', $query, 'R9 — a non-empty message must travel in the text= parameter.');
        self::assertSame($expectedMessage, rawurldecode(substr($query, strlen('text='))));
    }
}
