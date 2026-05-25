<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Phar;

use Symfony\Component\Console\Input\InputInterface;

final readonly class SfxSourceResolver
{
    private const DEFAULT_SFX_URL = 'https://download.workerman.net/php/php%s.micro.sfx';

    /**
     * @param mixed[] $buildConfig
     */
    public function resolve(InputInterface $input, array $buildConfig): SfxSource
    {
        $sfxFile = $input->getOption('sfx-file');
        if ($this->isNonEmptyString($sfxFile)) {
            if (!is_file($sfxFile)) {
                throw new \RuntimeException(sprintf('SFX file not found: %s', $sfxFile));
            }

            return new SfxSource(localPath: $sfxFile, url: null, checksum: null, allowInsecure: false);
        }

        $configSfxFile = $buildConfig['sfx']['file'] ?? null;
        if ($this->isNonEmptyString($configSfxFile) && is_file($configSfxFile)) {
            return new SfxSource(localPath: $configSfxFile, url: null, checksum: null, allowInsecure: false);
        }

        $url = $this->resolveSfxUrl($input, $buildConfig);
        $checksum = $this->resolveChecksum($input, $buildConfig);
        $allowInsecure = $this->resolveAllowInsecure($input, $buildConfig);
        $resolvedPhpVersion = $this->resolvePhpVersion($input, $buildConfig);

        return new SfxSource(localPath: null, url: $url, checksum: $checksum, allowInsecure: $allowInsecure, resolvedPhpVersion: $resolvedPhpVersion);
    }

    /**
     * @param mixed[] $buildConfig
     */
    public function resolveSfxUrl(InputInterface $input, array $buildConfig): string
    {
        $sfxUrl = $input->getOption('sfx-url');
        if ($this->isNonEmptyString($sfxUrl)) {
            return $sfxUrl;
        }

        $sfxUrl = $buildConfig['sfx']['url'] ?? null;
        if ($this->isNonEmptyString($sfxUrl)) {
            return $sfxUrl;
        }

        $phpVersion = $this->resolvePhpVersionString($input, $buildConfig);

        return sprintf(self::DEFAULT_SFX_URL, $phpVersion);
    }

    /**
     * Returns the resolved PHP version if the URL was built from the default template,
     * or null when a custom URL was provided.
     *
     * @param mixed[] $buildConfig
     */
    private function resolvePhpVersion(InputInterface $input, array $buildConfig): ?string
    {
        $sfxUrl = $input->getOption('sfx-url');
        if ($this->isNonEmptyString($sfxUrl)) {
            return null;
        }

        $sfxUrl = $buildConfig['sfx']['url'] ?? null;
        if ($this->isNonEmptyString($sfxUrl)) {
            return null;
        }

        return $this->resolvePhpVersionString($input, $buildConfig);
    }

    /**
     * @param mixed[] $buildConfig
     */
    private function resolvePhpVersionString(InputInterface $input, array $buildConfig): string
    {
        $phpVersion = $input->getOption('php-version');
        if (!$this->isNonEmptyString($phpVersion)) {
            $phpVersion = $buildConfig['bin_php_version'] ?? null;
        }
        if (!$this->isNonEmptyString($phpVersion)) {
            $phpVersion = sprintf('%s.%s', PHP_MAJOR_VERSION, PHP_MINOR_VERSION);
        }

        return $phpVersion;
    }

    /**
     * @param mixed[] $buildConfig
     */
    public function resolveChecksum(InputInterface $input, array $buildConfig): ?string
    {
        $checksum = $input->getOption('sfx-checksum');
        if ($this->isNonEmptyString($checksum)) {
            return $checksum;
        }

        $checksum = $buildConfig['sfx']['sha256'] ?? null;

        return $this->isNonEmptyString($checksum) ? $checksum : null;
    }

    /**
     * @param mixed[] $buildConfig
     */
    public function resolveAllowInsecure(InputInterface $input, array $buildConfig): bool
    {
        return (bool) $input->getOption('insecure') || (bool) ($buildConfig['sfx']['allow_insecure'] ?? false);
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }
}
