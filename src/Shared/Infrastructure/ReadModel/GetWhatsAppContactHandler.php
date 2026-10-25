<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\ReadModel;

use App\Shared\Application\Query\GetWhatsAppContact;
use App\Shared\Domain\WhatsApp\WhatsAppChatLink;
use App\Shared\Domain\WhatsApp\WhatsAppMessage;
use App\Shared\Domain\WhatsApp\WhatsAppNumber;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * R1 / R17 / R18 — the contact is answered from configuration only: no database access, no write,
 * no dispatch (QRY-001/QRY-003). R4 — the configured number is validated as the handler is built,
 * so an unparseable WHATSAPP_PHONE_NUMBER fails as a configuration error before a request can be
 * answered from it, never as a 200 carrying a link.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class GetWhatsAppContactHandler
{
    private readonly WhatsAppNumber $number;

    public function __construct(
        #[Autowire('%env(WHATSAPP_PHONE_NUMBER)%')]
        string $phoneNumber,
        #[Autowire('%env(WHATSAPP_DEFAULT_MESSAGE)%')]
        private readonly string $defaultMessage,
    ) {
        $this->number = WhatsAppNumber::fromString($phoneNumber);
    }

    /** @return array{phoneNumber: string, message: string, chatUrl: string} */
    public function __invoke(GetWhatsAppContact $query): array
    {
        // R8/R11 — a contextual message that carries content replaces the default; absent, empty
        // and ASCII-spaces-only values fall back to it. Only spaces count as blank: a tab, a newline
        // or a null byte is bad input, so it reaches fromContextual() and becomes a 400 instead of
        // being swallowed into the default (R12).
        $message = null !== $query->contextualMessage && '' !== trim($query->contextualMessage, ' ')
            ? WhatsAppMessage::fromContextual($query->contextualMessage)
            : WhatsAppMessage::fromConfigured($this->defaultMessage);

        return [
            'phoneNumber' => $this->number->digits(),
            'message' => $message->value(),
            'chatUrl' => WhatsAppChatLink::build($this->number, $message->value()),
        ];
    }
}
