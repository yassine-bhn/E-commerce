<?php

declare(strict_types=1);

namespace App\Shared\Domain\WhatsApp;

/**
 * R12 — a contextual message the endpoint cannot use (too long, control characters, invalid UTF-8)
 * is the caller's mistake, so the HTTP layer can answer it with a 400 while a broken configuration
 * keeps failing as a server error. ERR-002 — a business refusal is a \DomainException.
 */
final class InvalidContextualMessageException extends \DomainException
{
    public static function becauseItIsNotUtf8(): self
    {
        return new self('Contextual WhatsApp message is not valid UTF-8.');
    }

    public static function becauseItCarriesNoContent(): self
    {
        return new self('Contextual WhatsApp message must contain at least one non-space character.');
    }

    public static function becauseOfLength(int $maxCodePoints, int $actualCodePoints): self
    {
        return new self(sprintf(
            'Contextual WhatsApp message must be at most %d code points long, %d given.',
            $maxCodePoints,
            $actualCodePoints,
        ));
    }

    public static function becauseOfControlCharacters(): self
    {
        return new self('Contextual WhatsApp message must not contain control characters.');
    }
}
