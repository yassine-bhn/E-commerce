<?php

declare(strict_types=1);

namespace App\Shared\Domain\Http;

/**
 * The comma-separated STOREFRONT_CORS_ORIGIN value, split and parsed once at construction, as the
 * single interpreter of the variable: every consumer judges a candidate origin through this list,
 * never through its own parsing of the raw string.
 *
 * The two judgements the variable serves stay distinct:
 * - contains() compares canonically (lowercased, default port elided) — the way the cross-site
 *   guard judges an Origin or Referer value;
 * - containsRaw() compares the exact header spelling — the way the CORS answer echoes the
 *   browser's own Origin header value back to it, character for character.
 *
 * An entry that names no origin can never match canonically, and an empty or whitespace-only
 * segment names no origin at all, so neither participates in either judgement.
 */
final readonly class OriginAllowList
{
    /** @var list<string> */
    private array $rawEntries;

    /** @var list<Origin> */
    private array $origins;

    private function __construct(string $commaSeparated)
    {
        $rawEntries = [];
        $origins = [];

        foreach (explode(',', $commaSeparated) as $entry) {
            $raw = trim($entry);

            if ('' === $raw) {
                continue;
            }

            $rawEntries[] = $raw;

            $origin = Origin::fromUrl($raw);

            if (null !== $origin) {
                $origins[] = $origin;
            }
        }

        $this->rawEntries = $rawEntries;
        $this->origins = $origins;
    }

    public static function fromCommaSeparated(string $commaSeparated): self
    {
        return new self($commaSeparated);
    }

    /**
     * Canonical judgement: every spelling of an allow-listed origin matches, because the guard
     * compares origins, not header strings.
     */
    public function contains(Origin $origin): bool
    {
        foreach ($this->origins as $allowed) {
            if ($allowed->equals($origin)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Exact-string judgement: only the configured spelling matches, because the CORS answer must
     * echo the browser's own Origin header value back verbatim.
     */
    public function containsRaw(string $origin): bool
    {
        return \in_array($origin, $this->rawEntries, true);
    }
}
