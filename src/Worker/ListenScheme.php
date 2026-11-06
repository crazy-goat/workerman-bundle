<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Worker;

use CrazyGoat\WorkermanBundle\Exception\UnsupportedListenSchemeException;

/**
 * Supported listen schemes for Workerman server configuration.
 *
 * Each case maps a user-facing scheme (e.g. "https") to the internal
 * Workerman listen prefix and transport that Workerman expects.
 *
 * @see https://github.com/crazy-goat/workerman-bundle?tab=readme-ov-file
 */
enum ListenScheme: string
{
    case Http = 'http';
    case Https = 'https';

    /**
     * Parse a listen URL string and return the matching scheme.
     *
     * Extracts the scheme prefix (e.g. "https" from "https://0.0.0.0:443")
     * and maps it to the corresponding enum case.
     *
     * @param string $listen The full listen URL (e.g. "http://0.0.0.0:80")
     * @return self The matching scheme
     * @throws UnsupportedListenSchemeException If the scheme is not supported
     */
    public static function fromListen(string $listen): self
    {
        // Only http:// and https:// map to enum cases. ws:// and wss:// are
        // rejected with a dedicated message until WebSocket support lands;
        // check them before the generic fallback so the error is actionable.
        return match (true) {
            str_starts_with($listen, 'https://') => self::Https,
            str_starts_with($listen, 'wss://'),
            str_starts_with($listen, 'ws://') => throw new UnsupportedListenSchemeException(sprintf(
                'WebSocket listeners (ws://, wss://) are not supported yet in "%s". Use http:// or https://.',
                $listen,
            )),
            str_starts_with($listen, 'http://')  => self::Http,
            default => throw new UnsupportedListenSchemeException(sprintf(
                'Unsupported listen scheme in "%s". Supported schemes: http://, https://',
                $listen,
            )),
        };
    }

    /**
     * The Workerman listen prefix for this scheme.
     *
     * Workerman uses different protocol prefixes internally:
     * - http:// remains http://
     * - https:// becomes http:// (SSL is handled via transport)
     */
    public function workermanPrefix(): string
    {
        return match ($this) {
            self::Http, self::Https => 'http://',
        };
    }

    /**
     * The Workerman transport for this scheme.
     *
     * - http:// uses plain tcp
     * - https:// uses ssl (requires SSL context configuration)
     */
    public function transport(): string
    {
        return match ($this) {
            self::Https => 'ssl',
            self::Http  => 'tcp',
        };
    }

    /**
     * Whether this scheme requires SSL context configuration.
     */
    public function requiresSslContext(): bool
    {
        return $this->transport() === 'ssl';
    }
}
