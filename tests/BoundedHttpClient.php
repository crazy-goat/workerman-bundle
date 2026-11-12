<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;

final class BoundedHttpClient
{
    public const OPTIONS = ['http_errors' => false, 'connect_timeout' => 0.2, 'timeout' => 1];

    /**
     * @param array<string, mixed> $options
     * @throws \InvalidArgumentException
     */
    public static function create(array $options = [], ?MockHandler $handler = null): Client
    {
        $options += self::OPTIONS;
        foreach (['connect_timeout', 'timeout'] as $key) {
            if (!is_int($options[$key]) && !is_float($options[$key]) || $options[$key] <= 0) {
                throw new \InvalidArgumentException(sprintf('"%s" must be a positive number of seconds (#1028).', $key));
            }
        }

        return new Client(
            $options + ($handler instanceof MockHandler ? ['handler' => HandlerStack::create($handler)] : []),
        );
    }
}
