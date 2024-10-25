<?php

declare(strict_types=1);

namespace Luzrain\WorkermanBundle\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsProcess
{
    public function __construct(
        public ?string $name = null,
        public ?int $processes = null,
        public ?string $method = null,
    ) {
    }
}
