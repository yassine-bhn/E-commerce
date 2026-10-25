<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Behavior 10 (spec-run-9/spec.md R2, R22) — an operator can configure and call the WhatsApp
 * contact endpoint without reading code: both variables are declared where the other keys live,
 * the production stack delivers them to the application, and the README plus the operator page
 * carry the path, the response contract and the 400 case. No source file carries the number.
 *
 * Repository-state guard, hence the Architecture suite rather than Unit.
 */
final class WhatsAppContactDocumentationTest extends TestCase
{
    private const string ROUTE = '/api/v1/storefront/contact/whatsapp';

    #[DataProvider('environmentFileProvider')]
    public function testEveryEnvironmentFileDeclaresTheTwoKeysAndTheFormatRule(string $environmentFile): void
    {
        $contents = self::read($environmentFile);

        self::assertMatchesRegularExpression('/^WHATSAPP_PHONE_NUMBER=\S+/m', $contents, 'R1 — the number key is declared, not commented out.');
        self::assertMatchesRegularExpression('/^WHATSAPP_DEFAULT_MESSAGE=/m', $contents, 'R1 — the message key is declared, not commented out.');
        self::assertStringContainsString('digits only', strtolower($contents), 'R22 — the format rule is documented next to the key.');
    }

    /** @return iterable<string, array{string}> */
    public static function environmentFileProvider(): iterable
    {
        yield '.env' => ['.env'];
        yield '.env.test' => ['.env.test'];
        yield '.env.prod.example' => ['.env.prod.example'];
    }

    /**
     * R22 — delivery is proven on the parsed YAML, never on a substring: a comment line, a key
     * nested outside `environment` or a dropped anchor all leave the raw text looking correct.
     * The anchor is asserted alongside every service, so removing a key from it fails even when
     * a service carries its own copy.
     */
    #[DataProvider('backendServiceProvider')]
    public function testTheProductionStackForwardsBothKeysToEveryBackendService(string $service): void
    {
        $compose = self::composeFile();
        $anchor = self::mapping($compose['x-app-environment'] ?? null, 'R22 — compose.prod.yaml must declare the x-app-environment anchor every backend service inherits.');
        $environment = self::mapping($compose['services'][$service]['environment'] ?? null, sprintf('R22 — services.%s must receive an environment mapping from the x-app-environment anchor.', $service));

        self::assertArrayHasKey('WHATSAPP_PHONE_NUMBER', $anchor, 'R22 — the x-app-environment anchor must declare WHATSAPP_PHONE_NUMBER, so a service-level copy cannot hide its removal.');
        self::assertArrayHasKey('WHATSAPP_DEFAULT_MESSAGE', $anchor, 'R22 — the x-app-environment anchor must declare WHATSAPP_DEFAULT_MESSAGE, so a service-level copy cannot hide its removal.');
        self::assertArrayHasKey('WHATSAPP_PHONE_NUMBER', $environment, sprintf('R22 — services.%s must deliver WHATSAPP_PHONE_NUMBER to the application.', $service));
        self::assertArrayHasKey('WHATSAPP_DEFAULT_MESSAGE', $environment, sprintf('R22 — services.%s must deliver WHATSAPP_DEFAULT_MESSAGE to the application.', $service));
    }

    /** @return iterable<string, array{string}> */
    public static function backendServiceProvider(): iterable
    {
        yield 'app' => ['app'];
        yield 'worker' => ['worker'];
        yield 'outbox' => ['outbox'];
    }

    /**
     * R1/R22 — the anchor is the one place either value is declared; that every backend service
     * delivers the declaration is the sibling test's behavior.
     */
    public function testTheProductionStackRequiresTheNumberAndDefaultsTheMessage(): void
    {
        $anchor = self::mapping(self::composeFile()['x-app-environment'] ?? null, 'R22 — compose.prod.yaml must declare the x-app-environment anchor every backend service inherits.');

        $number = self::stringValue($anchor['WHATSAPP_PHONE_NUMBER'] ?? null, 'R1 — the production stack must declare WHATSAPP_PHONE_NUMBER.');
        $message = self::stringValue($anchor['WHATSAPP_DEFAULT_MESSAGE'] ?? null, 'R22 — the production stack must declare WHATSAPP_DEFAULT_MESSAGE even when it is empty.');

        self::assertStringStartsWith('${WHATSAPP_PHONE_NUMBER:?', $number, 'R1 — compose must refuse to start when WHATSAPP_PHONE_NUMBER is unset, so the endpoint never answers without a configured number.');
        self::assertSame('${WHATSAPP_DEFAULT_MESSAGE:-}', $message, 'R22 — an intentionally empty default message must not fail the stack, so the message must default instead of being required.');
    }

    public function testTheReadmeDocumentsTheEndpointAndItsConfiguration(): void
    {
        $readme = self::read('README.md');
        $section = self::section($readme, 'WhatsApp');

        self::assertStringContainsString(self::ROUTE, $section, 'R22 — the README links the documented path to the feature.');
        self::assertStringContainsString('WHATSAPP_PHONE_NUMBER', $section);
        self::assertStringContainsString('WHATSAPP_DEFAULT_MESSAGE', $section);
        self::assertStringContainsString('wa.me', $section);
        self::assertStringContainsString('400', $section);
    }

    public function testTheOperatorPageDocumentsTheContractTheParameterAndTheRejection(): void
    {
        $page = self::read('docs/whatsapp-contact-endpoint.md');

        self::assertStringContainsString(self::ROUTE, $page);
        self::assertStringContainsString('phoneNumber', $page);
        self::assertStringContainsString('chatUrl', $page);
        self::assertStringContainsString('?message=', $page);
        self::assertStringContainsString('https://wa.me/', $page);
        self::assertStringContainsString('WHATSAPP_PHONE_NUMBER', $page);
        self::assertStringContainsString('WHATSAPP_DEFAULT_MESSAGE', $page);
        self::assertStringContainsString('digits only', strtolower($page));
        self::assertStringContainsString('400', $page);
        self::assertStringContainsString('{"error"', $page);
        self::assertStringContainsString('no database', strtolower($page));
    }

    public function testNoSourceFileCarriesTheConfiguredWhatsAppNumber(): void
    {
        $configured = $_ENV['WHATSAPP_PHONE_NUMBER'] ?? $_SERVER['WHATSAPP_PHONE_NUMBER'] ?? null;
        self::assertIsString($configured, 'R1 — WHATSAPP_PHONE_NUMBER must be declared for the test environment.');

        self::assertStringNotContainsString(
            ltrim($configured, '+'),
            self::sourceCorpus(),
            'R2 — changing the number must stay a configuration-only change.',
        );
    }

    public function testNoSourceFileCarriesTheConfiguredDefaultMessage(): void
    {
        $configured = $_ENV['WHATSAPP_DEFAULT_MESSAGE'] ?? $_SERVER['WHATSAPP_DEFAULT_MESSAGE'] ?? null;
        self::assertIsString($configured, 'R1 — WHATSAPP_DEFAULT_MESSAGE must be declared for the test environment.');
        self::assertGreaterThan(20, strlen($configured), 'R2 — the configured message must be distinctive enough to detect a copy in src/**.');

        self::assertStringNotContainsString(
            $configured,
            self::sourceCorpus(),
            'R2 — rewording the message must stay a configuration-only change too.',
        );
    }

    /**
     * The section of a markdown file that starts at the heading containing $topic and ends at the
     * next level-2 heading, so a later section cannot satisfy an assertion by accident.
     */
    private static function section(string $markdown, string $topic): string
    {
        $found = preg_match(
            sprintf('/^#+ .*%s.*$/m', preg_quote($topic, '/')),
            $markdown,
            $heading,
            PREG_OFFSET_CAPTURE,
        );
        self::assertSame(1, $found, sprintf('R22 — README has no "%s" heading.', $topic));

        $section = substr($markdown, (int) $heading[0][1]);
        $next = [];
        preg_match('/^## /m', $section, $next, PREG_OFFSET_CAPTURE, 1);

        return isset($next[0]) ? substr($section, 0, (int) $next[0][1]) : $section;
    }

    /** @return array<array-key, mixed> */
    private static function composeFile(): array
    {
        $path = dirname(__DIR__, 2).'/compose.prod.yaml';
        self::assertFileExists($path, 'R22 — compose.prod.yaml must exist at the repository root.');

        return self::mapping(Yaml::parseFile($path), 'R22 — compose.prod.yaml must parse to a YAML mapping.');
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function mapping(mixed $value, string $message): array
    {
        self::assertIsArray($value, $message);

        return (array) $value;
    }

    private static function stringValue(mixed $value, string $message): string
    {
        self::assertIsString($value, $message);

        return (string) $value;
    }

    private static function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 2).'/'.$relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private static function sourceCorpus(): string
    {
        $files = iterator_to_array((new Finder())->files()->in(dirname(__DIR__, 2).'/src')->name('*.php'));

        return implode("\n", array_map(
            static fn (\SplFileInfo $file): string => (string) file_get_contents($file->getPathname()),
            $files,
        ));
    }
}
