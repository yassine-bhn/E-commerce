<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\WhatsApp;

use App\Shared\Application\Query\GetWhatsAppContact;
use App\Shared\Domain\WhatsApp\InvalidContextualMessageException;
use App\Shared\Infrastructure\ReadModel\GetWhatsAppContactHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Behavior 9 (spec-run-9/spec.md R4) — the configured number is validated when the handler is
 * built, so an unparseable WHATSAPP_PHONE_NUMBER is a configuration error raised before any
 * request can be answered from it, instead of a per-request failure with a link halfway built.
 */
final class GetWhatsAppContactHandlerTest extends TestCase
{
    public function testRejectsAnUnparseableConfiguredNumberWhenTheHandlerIsBuilt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not 8 to 15 digits');

        new GetWhatsAppContactHandler('216XXXXXXXX', 'Hello, I would like more information.');
    }

    public function testAnswersTheConfiguredNumberAndDefaultMessage(): void
    {
        $handler = new GetWhatsAppContactHandler('+21600000000', 'Default message');

        self::assertSame(
            [
                'phoneNumber' => '21600000000',
                'message' => 'Default message',
                'chatUrl' => 'https://wa.me/21600000000?text=Default%20message',
            ],
            $handler(new GetWhatsAppContact()),
        );
    }

    public function testUsesTheContextualMessageAsGiven(): void
    {
        $handler = new GetWhatsAppContactHandler('21600000000', 'Default message');

        $contact = $handler(new GetWhatsAppContact('Bonjour, je souhaite un devis'));

        self::assertSame('Bonjour, je souhaite un devis', $contact['message']);
        self::assertSame(
            'https://wa.me/21600000000?text=Bonjour%2C%20je%20souhaite%20un%20devis',
            $contact['chatUrl'],
        );
    }

    #[DataProvider('blankContextualMessageProvider')]
    public function testFallsBackToTheDefaultForABlankContextualMessage(?string $blank): void
    {
        $handler = new GetWhatsAppContactHandler('21600000000', 'Default message');

        self::assertSame('Default message', $handler(new GetWhatsAppContact($blank))['message']);
    }

    /** @return iterable<string, array{?string}> */
    public static function blankContextualMessageProvider(): iterable
    {
        yield 'absent' => [null];
        yield 'empty' => [''];
        yield 'spaces only' => ['   '];
    }

    public function testDoesNotSwallowAContextualMessageMadeOnlyOfControlCharacters(): void
    {
        $handler = new GetWhatsAppContactHandler('21600000000', 'Default message');

        $this->expectException(InvalidContextualMessageException::class);

        $handler(new GetWhatsAppContact("\t"));
    }
}
