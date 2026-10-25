<?php

declare(strict_types=1);

namespace App\Shared\Domain\WhatsApp;

final class WhatsAppChatLink
{
    private const string BASE_URL = 'https://wa.me/';

    public static function build(WhatsAppNumber $number, string $message): string
    {
        $url = self::BASE_URL.$number->digits();

        if ('' === $message) {
            return $url;
        }

        return $url.'?text='.rawurlencode($message);
    }
}
