<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Phar;

/**
 * @internal
 */
final readonly class PharBuilder
{
    private PharCapabilities $capabilities;

    public function __construct(
        private string $projectDir,
        private string $environment,
        ?PharCapabilities $capabilities = null,
    ) {
        $this->capabilities = $capabilities ?? PharCapabilities::probe();
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
     * @throws \RuntimeException
     */
    public function build(array $buildConfig, string $pharPath, bool $includeTests = false): string
    {
        $this->capabilities->assertCanBuild();

        $buildDir = \dirname($pharPath);
        if (!is_dir($buildDir) && !mkdir($buildDir, 0755, true) && !is_dir($buildDir)) {
            throw new \RuntimeException(sprintf('Unable to create build directory "%s".', $buildDir));
        }

        if (file_exists($pharPath)) {
            unlink($pharPath);
        }

        $pharFilename = basename($pharPath);
        $this->validatePharAlias($pharFilename);

        $kernelClass = ($buildConfig['kernel_class'] ?? '') !== ''
            ? $buildConfig['kernel_class']
            : 'App\\Kernel';
        $this->validateKernelClass($kernelClass);

        $excludePatterns = $this->buildExcludePatterns($buildConfig);
        $excludeFiles = $this->buildExcludeFiles($buildConfig);
        $filter = new PharFileFilter(
            $this->projectDir,
            $includeTests,
            $excludePatterns,
            $excludeFiles,
        );

        $phar = new \Phar($pharPath, 0, $pharFilename);
        $phar->startBuffering();

        $directory = new \RecursiveDirectoryIterator(
            $this->projectDir,
            \RecursiveDirectoryIterator::SKIP_DOTS,
        );
        $iterator = new \RecursiveIteratorIterator($directory);

        $filtered = new \CallbackFilterIterator($iterator, $filter->shouldInclude(...));

        $limitGuard = new PcreLimitGuard();
        $limitGuard->enter();
        try {
            $phar->buildFromIterator($filtered, $this->projectDir);
            $phar->setStub($this->generateStub($buildConfig, $pharFilename));
            $phar->stopBuffering();
        } finally {
            $limitGuard->exit();
        }

        unset($phar);

        return $pharPath;
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
     * @return string[]
     */
    private function buildExcludeFiles(array $buildConfig): array
    {
        $raw = $buildConfig['exclude_files'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, static fn(string $f): bool => $f !== ''));
    }

    public static function isExcluded(string $relativePath): bool
    {
        if (preg_match('#^(build/|\\.git/|\\.github/|tests/|docs/|var/)#', $relativePath)) {
            return true;
        }

        if (preg_match('#^(phpunit\.xml|\.php-cs-fixer|phpstan\.neon|rector\.php)$#', $relativePath)) {
            return true;
        }

        if (preg_match('#^[^/]+\.(phar|bin)$#', $relativePath)) {
            return true;
        }

        return (bool) preg_match('#^\.env#', $relativePath);
    }

    private const ALLOWED_ALIAS_PATTERN = '/^[A-Za-z0-9._-]+$/';

    private const ALLOWED_KERNEL_CLASS_PATTERN = '/^(\\\\?[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)+(\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*$/';

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
    public function generateStub(array $buildConfig, string $pharAlias = 'app.phar'): string
    {
        $this->validatePharAlias($pharAlias);

        $runtimeEnv = \CrazyGoat\WorkermanBundle\Runtime::class;
        $kernelClass = ($buildConfig['kernel_class'] ?? '') !== ''
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
     * @throws \RuntimeException
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
     * @throws \RuntimeException
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
