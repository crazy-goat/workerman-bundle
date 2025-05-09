<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsTask
{
    public function __construct(
        public ?string $name = null,
        public ?string $schedule = null,
        public ?string $method = null,
        public ?int $jitter = null,
    ) {
    }
}
