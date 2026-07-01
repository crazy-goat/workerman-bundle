<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Phar;

/**
 * Probes the runtime environment for PHAR-building capabilities.
 *
 * Centralises the side-effectful checks that `PharBuilder` previously
 * performed inline (`phar.readonly` INI probe, `\Phar` extension presence).
 * Extracting them into a dedicated collaborator makes the checks
 * individually testable and lets `PharBuilder` accept a stub in unit tests.
 *
 * @internal
 */
final readonly class PharCapabilities
{
    public function __construct(
        private bool $pharReadOnly,
        private bool $pharExtensionLoaded,
    ) {
    }

    /**
     * Probe the live PHP runtime.
     */
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
     * Assert that the runtime can build PHAR archives.
     *
     * @throws \RuntimeException when a required capability is missing
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
