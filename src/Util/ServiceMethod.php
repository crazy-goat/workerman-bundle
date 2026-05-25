<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Util;

/**
 * @internal
 */
final readonly class ServiceMethod
{
    public function __construct(
        public string $serviceId,
        public string $method,
    ) {
        if ($serviceId === '' || $method === '') {
            throw new \InvalidArgumentException(
                sprintf('Service ID and method must not be empty, got "%s" and "%s".', $serviceId, $method),
            );
        }
    }

    public function toString(): string
    {
        return $this->serviceId . '::' . $this->method;
    }
}
