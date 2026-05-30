<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Attribute;

/**
 * Marks a service class as a managed process for the Workerman supervisor.
 *
 * Apply this attribute to any service class registered in the container to have
 * the supervisor spawn and manage one or more persistent child processes. The
 * supervisor monitors these processes and restarts them on failure.
 *
 * This attribute is consumed by ServicesConfigurator::configureAutoConfiguration()
 * which registers the `workerman.process` tag for each annotated class. The tag
 * is then picked up by the supervisor at runtime.
 *
 * @see ServicesConfigurator::configureAutoConfiguration()
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsProcess
{
    /**
     * @param string|null $name       A human-readable name for the process.
     *                                If null, the service ID is used.
     * @param int|null    $processes  Number of child processes to spawn.
     *                                Defaults to 1 when null.
     * @param string|null $method     The method to call on each process start.
     *                                Defaults to `__invoke` when null.
     */
    public function __construct(
        public ?string $name = null,
        public ?int $processes = null,
        public ?string $method = null,
    ) {
    }
}
