<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Worker;

use CrazyGoat\WorkermanBundle\Exception\UnsupportedListenSchemeException;

/**
 * @see https://github.com/crazy-goat/workerman-bundle?tab=readme-ov-file
 */
enum ListenScheme: string
{
    case Http = 'http';
    case Https = 'https';

    /**
     * @throws UnsupportedListenSchemeException
     */
    public static function fromListen(string $listen): self
    {
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

    public function workermanPrefix(): string
    {
        return match ($this) {
            self::Http, self::Https => 'http://',
        };
    }

    public function transport(): string
    {
        return match ($this) {
            self::Https => 'ssl',
            self::Http  => 'tcp',
        };
    }

    public function requiresSslContext(): bool
    {
        return $this->transport() === 'ssl';
    }
}
