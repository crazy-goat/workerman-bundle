<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;

/**
 * Shared factory for the HTTP clients of the test suite (issue #1028).
 *
 * A client without timeouts has no deadline on a stalled response, so the
 * test suite can hang. Every real client must carry a connect timeout and a
 * total timeout; {@see BoundedHttpClientTest} fails when a client without
 * them is added.
 */
final class BoundedHttpClient
{
    /** A stalled response must fail the test, not hang the suite (#830). */
    public const OPTIONS = ['http_errors' => false, 'connect_timeout' => 0.2, 'timeout' => 1];

    /**
     * @param array<string, mixed> $options extra client options; a longer
     *                                      'timeout' wins where a test needs it
     */
    public static function create(array $options = [], ?MockHandler $handler = null): Client
    {
        return new Client(
            $options + self::OPTIONS + ($handler instanceof MockHandler ? ['handler' => HandlerStack::create($handler)] : []),
        );
    }
}
