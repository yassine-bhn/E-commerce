<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\WhatsApp;

use App\Shared\Domain\WhatsApp\InvalidContextualMessageException;
use App\Shared\Domain\WhatsApp\WhatsAppMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WhatsAppMessageTest extends TestCase
{
    private const string CONFIGURED_MESSAGE = 'Hello, I would like more information about your products.';
    private const string ARABIC_MESSAGE = 'مرحبا، أريد معلومات';

    public function testKeepsTheContextualMessageExactlyAsGiven(): void
    {
        $message = WhatsAppMessage::fromContextual('  Hello there  ');

        self::assertSame('  Hello there  ', $message->value(), 'R8/R12 — the caller\'s text is never trimmed or sanitised.');
    }

    public function testAcceptsAContextualMessageOfExactlyTwoHundredCodePointsWithoutTruncatingIt(): void
    {
        $raw = str_repeat('é', 200);

        $message = WhatsAppMessage::fromContextual($raw);

        self::assertSame(200, mb_strlen($message->value()));
        self::assertSame($raw, $message->value());
    }

    public function testRejectsAContextualMessageOfTwoHundredAndOneCodePoints(): void
    {
        $this->expectException(InvalidContextualMessageException::class);

        WhatsAppMessage::fromContextual(str_repeat('é', 201));
    }

    public function testRejectsATrailingNewlineInsteadOfTrimmingItAway(): void
    {
        $this->expectException(InvalidContextualMessageException::class);

        WhatsAppMessage::fromContextual("Hello\n");
    }

    public function testRejectsAContextualMessageMadeOnlyOfControlCharacters(): void
    {
        $this->expectException(InvalidContextualMessageException::class);

        WhatsAppMessage::fromContextual("\x00");
    }

    public function testIsADomainExceptionSoTheEdgeCanMapItWithoutWideningTheCatch(): void
    {
        $exception = InvalidContextualMessageException::becauseOfControlCharacters();

        self::assertInstanceOf(\DomainException::class, $exception, 'ERR-002 — a business refusal is a DomainException.');
    }

    public function testRoundTripsAnArabicContextualMessageByteForByte(): void
    {
        $message = WhatsAppMessage::fromContextual(self::ARABIC_MESSAGE);

        self::assertSame(self::ARABIC_MESSAGE, $message->value());
    }

    #[DataProvider('invalidContextualMessageProvider')]
    public function testRejectsABlankOrControlCharacterLadenContextualMessage(string $raw): void
    {
        $this->expectException(InvalidContextualMessageException::class);

        WhatsAppMessage::fromContextual($raw);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidContextualMessageProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
        yield 'newline' => ["line1\nline2"];
        yield 'tab' => ["tab\there"];
        yield 'null byte' => ["\x00"];
        yield 'bell character' => ['a'."\x07".'b'];
        yield 'invalid utf-8' => ["\xC3\x28"];
    }

    public function testKeepsAnEmptyConfiguredMessage(): void
    {
        $message = WhatsAppMessage::fromConfigured('');

        self::assertSame('', $message->value());
    }

    public function testRoundTripsTheConfiguredDefaultMessage(): void
    {
        $message = WhatsAppMessage::fromConfigured(self::CONFIGURED_MESSAGE);

        self::assertSame(self::CONFIGURED_MESSAGE, $message->value());
    }

    public function testHasNoLengthLimitForTheConfiguredMessage(): void
    {
        $configured = str_repeat('é', 201);

        $message = WhatsAppMessage::fromConfigured($configured);

        self::assertSame($configured, $message->value());
    }

    public function testRejectsAConfiguredMessageThatIsNotValidUtf8(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WhatsAppMessage::fromConfigured("\xC3\x28");
    }
}
