<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Attribute;

/**
 * @see ServicesConfigurator::configureAutoConfiguration()
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsProcess
{
    public function __construct(
        public ?string $name = null,
        public ?int $processes = null,
        public ?string $method = null,
        public ?int $restartDelay = null,
        public ?int $maxRestartDelay = null,
    ) {
    }
}
