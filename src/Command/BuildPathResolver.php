<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Command;

final class BuildPathResolver
{
    /**
     * @param array{
     *     build_dir?: string,
     *     kernel_class?: string,
     *     phar_filename?: string,
     *     bin_filename?: string,
     *     bin_php_version?: string|null,
     *     sfx?: array{
     *         url?: string|null,
     *         file?: string|null,
     *         sha256?: string|null,
     *         allow_insecure?: bool,
     *     },
     *     exclude_patterns?: list<string>,
     *     exclude_files?: list<string>,
     *     custom_ini?: string|null,
     * } $buildConfig
     */
    public function resolveBuildDir(mixed $cliBuildDir, array $buildConfig, string $projectDir): string
    {
        $buildDir = is_string($cliBuildDir) && $cliBuildDir !== ''
            ? $cliBuildDir
            : ($buildConfig['build_dir'] ?? $projectDir . '/build');

        if (!is_string($buildDir) || $buildDir === '') {
            throw new \RuntimeException('Invalid build configuration: build_dir must be a non-empty string.');
        }

        if (!str_starts_with($buildDir, '/')) {
            $buildDir = $projectDir . '/' . $buildDir;
        }

        return $buildDir;
    }

    /**
     * @param array{
     *     build_dir?: string,
     *     kernel_class?: string,
     *     phar_filename?: string,
     *     bin_filename?: string,
     *     bin_php_version?: string|null,
     *     sfx?: array{
     *         url?: string|null,
     *         file?: string|null,
     *         sha256?: string|null,
     *         allow_insecure?: bool,
     *     },
     *     exclude_patterns?: list<string>,
     *     exclude_files?: list<string>,
     *     custom_ini?: string|null,
     * } $buildConfig
     */
    public function resolvePharPath(mixed $cliPharFilename, string $buildDir, array $buildConfig): string
    {
        return $buildDir . '/' . $this->resolveFilename($cliPharFilename, $buildConfig, 'phar_filename', 'app.phar');
    }

    /**
     * @param array{
     *     build_dir?: string,
     *     kernel_class?: string,
     *     phar_filename?: string,
     *     bin_filename?: string,
     *     bin_php_version?: string|null,
     *     sfx?: array{
     *         url?: string|null,
     *         file?: string|null,
     *         sha256?: string|null,
     *         allow_insecure?: bool,
     *     },
     *     exclude_patterns?: list<string>,
     *     exclude_files?: list<string>,
     *     custom_ini?: string|null,
     * } $buildConfig
     */
    public function resolveBinPath(mixed $cliBinFilename, string $buildDir, array $buildConfig): string
    {
        return $buildDir . '/' . $this->resolveFilename($cliBinFilename, $buildConfig, 'bin_filename', 'app.bin');
    }

    /**
     * @param array{
     *     build_dir?: string,
     *     kernel_class?: string,
     *     phar_filename?: string,
     *     bin_filename?: string,
     *     bin_php_version?: string|null,
     *     sfx?: array{
     *         url?: string|null,
     *         file?: string|null,
     *         sha256?: string|null,
     *         allow_insecure?: bool,
     *     },
     *     exclude_patterns?: list<string>,
     *     exclude_files?: list<string>,
     *     custom_ini?: string|null,
     * } $buildConfig
     */
    private function resolveFilename(mixed $cliValue, array $buildConfig, string $configKey, string $default): string
    {
        $filename = is_string($cliValue) && $cliValue !== ''
            ? $cliValue
            : ($buildConfig[$configKey] ?? $default);

        if (!is_string($filename) || $filename === '') {
            return $default;
        }

        return $filename;
    }
}
