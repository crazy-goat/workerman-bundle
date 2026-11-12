<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Phar;

/**
 * @internal
 */
final readonly class PharCapabilities
{
    public function __construct(
        private bool $pharReadOnly,
        private bool $pharExtensionLoaded,
    ) {
    }

    public static function probe(): self
    {
        return new self(
            (bool) ini_get('phar.readonly'),
            class_exists(\Phar::class),
        );
    }

    public function isPharReadOnly(): bool
    {
        return $this->pharReadOnly;
    }

    public function isPharExtensionLoaded(): bool
    {
        return $this->pharExtensionLoaded;
    }

    /**
     * @throws \RuntimeException
     */
    public function assertCanBuild(): void
    {
        if ($this->pharReadOnly) {
            throw new \RuntimeException('phar.readonly must be disabled in php.ini. Set phar.readonly=0 and try again.');
        }

        if (!$this->pharExtensionLoaded) {
            throw new \RuntimeException('The Phar extension is required to build PHAR archives.');
        }
    }
}
