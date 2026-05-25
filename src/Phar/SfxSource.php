<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Phar;

final readonly class SfxSource
{
    public function __construct(
        public ?string $localPath,
        public ?string $url,
        public ?string $checksum,
        public bool $allowInsecure,
        public ?string $resolvedPhpVersion = null,
    ) {
    }

    public function isLocal(): bool
    {
        return $this->localPath !== null;
    }
}
