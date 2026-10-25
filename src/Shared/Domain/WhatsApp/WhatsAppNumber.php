<?php

declare(strict_types=1);

namespace App\Shared\Domain\WhatsApp;

final readonly class WhatsAppNumber
{
    private function __construct(private string $digits)
    {
    }

    public static function fromString(string $raw): self
    {
        $digits = str_starts_with($raw, '+') ? substr($raw, 1) : $raw;

        if (1 !== preg_match('/\A[0-9]{8,15}\z/', $digits)) {
            // R4/R14 — the value is not echoed: this message reaches the log and the debug error page,
            // and a key typo can hold anything, including a secret. deploy/release.sh is the place
            // that shows the operator which configured value was refused.
            throw new \InvalidArgumentException('WhatsApp number is not 8 to 15 digits, optionally prefixed by a single "+".');
        }

        return new self($digits);
    }

    public function digits(): string
    {
        return $this->digits;
    }
}
