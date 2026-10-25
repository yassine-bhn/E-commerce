<?php

declare(strict_types=1);

namespace App\Shared\Domain\WhatsApp;

final readonly class WhatsAppMessage
{
    private const int MAX_CONTEXTUAL_CODE_POINTS = 200;

    private function __construct(private string $value)
    {
    }

    public static function fromConfigured(string $configured): self
    {
        if (!mb_check_encoding($configured, 'UTF-8')) {
            throw new \InvalidArgumentException('Configured WhatsApp message is not valid UTF-8.');
        }

        return new self($configured);
    }

    public static function fromContextual(string $raw): self
    {
        if (!mb_check_encoding($raw, 'UTF-8')) {
            throw InvalidContextualMessageException::becauseItIsNotUtf8();
        }

        // R8/R11 — ASCII spaces carry no content: a spaces-only value is blank, not a message that
        // happens to start with spaces. Everything else is validated raw, never trimmed first, so a
        // trailing newline is refused instead of being quietly removed (R12).
        if ('' === trim($raw, ' ')) {
            throw InvalidContextualMessageException::becauseItCarriesNoContent();
        }

        $length = mb_strlen($raw, 'UTF-8');

        if ($length > self::MAX_CONTEXTUAL_CODE_POINTS) {
            throw InvalidContextualMessageException::becauseOfLength(self::MAX_CONTEXTUAL_CODE_POINTS, $length);
        }

        if (1 === preg_match('/\p{Cc}/u', $raw)) {
            throw InvalidContextualMessageException::becauseOfControlCharacters();
        }

        // R8/R10 — the caller's text is echoed back byte-for-byte; only the URL parameter is encoded.
        return new self($raw);
    }

    public function value(): string
    {
        return $this->value;
    }
}
