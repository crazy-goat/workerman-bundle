<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Phar;

/**
 * Builds a PHAR archive of the host Symfony application.
 *
 * Pure builder — takes a build configuration array and writes a PHAR.
 * Console UI lives in the command classes; this service is reusable
 * by both BuildPharCommand and BuildBinCommand without going through
 * Symfony's Command lifecycle.
 *
 * @internal
 */
final readonly class PharBuilder
{
    public function __construct(
        private string $projectDir,
        private string $environment,
    ) {
    }

    /**
     * @param mixed[] $buildConfig
     *
     * @return string Path to the built PHAR
     *
     * @throws \RuntimeException when the PHAR cannot be built
     */
    public function build(array $buildConfig, string $pharPath, bool $includeTests = false): string
    {
        if ((bool) ini_get('phar.readonly')) {
            throw new \RuntimeException('phar.readonly must be disabled in php.ini. Set phar.readonly=0 and try again.');
        }

        if (!class_exists(\Phar::class)) {
            throw new \RuntimeException('The Phar extension is required to build PHAR archives.');
        }

        $buildDir = \dirname($pharPath);
        if (!is_dir($buildDir) && !mkdir($buildDir, 0755, true) && !is_dir($buildDir)) {
            throw new \RuntimeException(sprintf('Unable to create build directory "%s".', $buildDir));
        }

        if (file_exists($pharPath)) {
            unlink($pharPath);
        }

        $pharFilename = basename($pharPath);
        $this->validatePharAlias($pharFilename);

        $kernelClass = \is_string($buildConfig['kernel_class'] ?? null) && $buildConfig['kernel_class'] !== ''
            ? $buildConfig['kernel_class']
            : 'App\\Kernel';
        $this->validateKernelClass($kernelClass);

        $phar = new \Phar($pharPath, 0, $pharFilename);
        $phar->startBuffering();

        $excludePatterns = $this->buildExcludePatterns($buildConfig);
        $excludeFiles = $this->buildExcludeFiles($buildConfig);
        $filter = new PharFileFilter(
            $this->projectDir,
            $includeTests,
            $excludePatterns,
            $excludeFiles,
        );

        $directory = new \RecursiveDirectoryIterator(
            $this->projectDir,
            \RecursiveDirectoryIterator::SKIP_DOTS,
        );
        $iterator = new \RecursiveIteratorIterator($directory);

        $filtered = new \CallbackFilterIterator($iterator, $filter->shouldInclude(...));

        $phar->buildFromIterator($filtered, $this->projectDir);
        $phar->setStub($this->generateStub($buildConfig, $pharFilename));
        $phar->stopBuffering();

        unset($phar);

        return $pharPath;
    }

    /**
     * @param mixed[] $buildConfig
     *
     * @return ExcludePattern[]
     */
    private function buildExcludePatterns(array $buildConfig): array
    {
        $raw = $buildConfig['exclude_patterns'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $patterns = [];
        foreach ($raw as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }
            $patterns[] = new ExcludePattern($pattern);
        }

        return $patterns;
    }

    /**
     * @param mixed[] $buildConfig
     *
     * @return string[]
     */
    private function buildExcludeFiles(array $buildConfig): array
    {
        $raw = $buildConfig['exclude_files'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, static fn($f): bool => is_string($f) && $f !== ''));
    }

    /**
     * Check if a relative path matches a built-in exclusion pattern.
     */
    public static function isExcluded(string $relativePath): bool
    {
        // Standard patterns: build dir, VCS, dev tools, cache, etc.
        if (preg_match('#^(build/|\\.git/|\\.github/|tests/|docs/|var/)#', $relativePath)) {
            return true;
        }

        // Config/dev files at the root
        if (preg_match('#^(phpunit\.xml|\.php-cs-fixer|phpstan\.neon|rector\.php)$#', $relativePath)) {
            return true;
        }

        // PHAR/binary output files at root
        if (preg_match('#^[^/]+\.(phar|bin)$#', $relativePath)) {
            return true;
        }

        // Env files at root
        return (bool) preg_match('#^\.env#', $relativePath);
    }

    /**
     * Valid characters for PHAR aliases: alphanumeric, dot, underscore, hyphen.
     */
    private const ALLOWED_ALIAS_PATTERN = '/^[A-Za-z0-9._-]+$/';

    /**
     * Valid PHP fully-qualified class name pattern.
     */
    private const ALLOWED_KERNEL_CLASS_PATTERN = '/^(\\\\?[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)+(\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*$/';

    /**
     * @param mixed[] $buildConfig
     *
     * Placeholders replaced in the stub template:
     *   __PHAR_ALIAS__    — PHAR archive alias
     *   __KERNEL_CLASS__  — Symfony kernel FQCN
     *   __RUNTIME_CLASS__ — Workerman Runtime class FQCN
     *   __APP_ENV__       — default environment name
     */
    public function generateStub(array $buildConfig, string $pharAlias = 'app.phar'): string
    {
        $this->validatePharAlias($pharAlias);

        $runtimeEnv = \CrazyGoat\WorkermanBundle\Runtime::class;
        $kernelClass = \is_string($buildConfig['kernel_class'] ?? null) && $buildConfig['kernel_class'] !== ''
            ? $buildConfig['kernel_class']
            : 'App\\Kernel';

        $this->validateKernelClass($kernelClass);

        $templatePath = __DIR__ . '/../../resources/phar-stub.tpl';
        $template = file_get_contents($templatePath);

        if ($template === false) {
            throw new \RuntimeException(sprintf('Unable to read PHAR stub template from "%s".', $templatePath));
        }

        return strtr($template, [
            '__PHAR_ALIAS__'    => $pharAlias,
            '__KERNEL_CLASS__'  => $kernelClass,
            '__RUNTIME_CLASS__' => $runtimeEnv,
            '__APP_ENV__'       => $this->environment,
        ]);
    }

    /**
     * Validate that the PHAR alias contains only safe characters.
     *
     * @throws \RuntimeException if the alias contains invalid characters
     */
    private function validatePharAlias(string $pharAlias): void
    {
        if ($pharAlias === '') {
            throw new \RuntimeException('PHAR alias must not be empty.');
        }

        if (preg_match(self::ALLOWED_ALIAS_PATTERN, $pharAlias) !== 1) {
            throw new \RuntimeException(sprintf(
                'PHAR alias "%s" contains invalid characters. Allowed: A-Z, a-z, 0-9, dot, underscore, hyphen.',
                $pharAlias,
            ));
        }
    }

    /**
     * Validate that the kernel class is a syntactically valid PHP FQCN.
     *
     * @throws \RuntimeException if the class name contains invalid characters
     */
    private function validateKernelClass(string $kernelClass): void
    {
        if ($kernelClass === '') {
            throw new \RuntimeException('Kernel class must not be empty.');
        }

        if (preg_match(self::ALLOWED_KERNEL_CLASS_PATTERN, $kernelClass) !== 1) {
            throw new \RuntimeException(sprintf(
                'Kernel class "%s" is not a valid PHP class name. Only letters, numbers, underscores, backslashes, and bytes 0x80-0xff are allowed.',
                $kernelClass,
            ));
        }
    }
}
