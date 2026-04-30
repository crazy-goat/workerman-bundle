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
     * Validate and split a service method string into service ID and method name.
     *
     * Expected format: "serviceId::methodName"
     *
     * @return array{string, string} [serviceId, methodName]
     */
    public static function split(string $service): array
    {
        $parts = explode('::', $service, 2);
        if (\count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new \InvalidArgumentException(
                sprintf('Invalid service method format "%s". Expected "serviceId::methodName".', $service),
            );
        }

        return [$parts[0], $parts[1]];
    }
}
