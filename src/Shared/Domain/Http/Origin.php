<?php

declare(strict_types=1);

namespace App\Shared\Domain\Http;

/**
 * The origin (scheme, host, port) of an absolute http(s) URL, in the one spelling origins are
 * compared in: scheme and host lowercased, and a port the scheme already implies elided, so that
 * "https://shop.example.com:443/x" and "https://shop.example.com" name the same origin.
 *
 * A value that is not an absolute http(s) URL — a bare path, another scheme, a string that carries
 * no host, the opaque "null" — is not an origin and parses to null, so it can never be trusted.
 */
final readonly class Origin
{
    /** A default port is implied by the scheme, so both spellings name the same origin. */
    private const DEFAULT_PORTS = [
        'http' => 80,
        'https' => 443,
    ];

    private function __construct(
        private string $scheme,
        private string $host,
        private ?int $port,
    ) {
    }

    public static function fromUrl(string $url): ?self
    {
        $parts = parse_url($url);

        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (!\in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $port = $parts['port'] ?? null;

        if (self::DEFAULT_PORTS[$scheme] === $port) {
            $port = null;
        }

        return new self($scheme, strtolower($parts['host']), $port);
    }

    public function equals(self $other): bool
    {
        return $this->scheme === $other->scheme
            && $this->host === $other->host
            && $this->port === $other->port;
    }

    /**
     * The canonical "scheme://host[:port]" spelling, identical for every URL of the origin.
     */
    public function toString(): string
    {
        return $this->scheme.'://'.$this->host.(null === $this->port ? '' : ':'.$this->port);
    }
}
