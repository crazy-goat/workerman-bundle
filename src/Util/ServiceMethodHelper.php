<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Util;

/**
 * @internal
 */
final class ServiceMethodHelper
{
    private function __construct()
    {
    }

    /**
     * Validate and split a service method string into a ServiceMethod value object.
     *
     * Expected format: "serviceId::methodName"
     */
    public static function split(string $service): ServiceMethod
    {
        $parts = explode('::', $service, 2);
        if (\count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new \InvalidArgumentException(
                sprintf('Invalid service method format "%s". Expected "serviceId::methodName".', $service),
            );
        }

        return new ServiceMethod($parts[0], $parts[1]);
    }
}
