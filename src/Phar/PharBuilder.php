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
        $phar = new \Phar($pharPath, 0, $pharFilename);
        $phar->startBuffering();

        $excludePatterns = isset($buildConfig['exclude_patterns']) && is_array($buildConfig['exclude_patterns'])
            ? $buildConfig['exclude_patterns']
            : [];
        $excludeFiles = isset($buildConfig['exclude_files']) && is_array($buildConfig['exclude_files'])
            ? $buildConfig['exclude_files']
            : [];

        $directory = new \RecursiveDirectoryIterator(
            $this->projectDir,
            \RecursiveDirectoryIterator::SKIP_DOTS,
        );
        $iterator = new \RecursiveIteratorIterator($directory);

        $projectDir = $this->projectDir;
        $filtered = new \CallbackFilterIterator($iterator, function (\SplFileInfo $file) use ($excludePatterns, $excludeFiles, $includeTests, $projectDir): bool {
            $relativePath = ltrim(
                str_replace([$projectDir . '/', $projectDir], '', $file->getPathname()),
                '/',
            );

            if ($relativePath === '') {
                return false;
            }

            if (!$includeTests && self::isExcluded($relativePath)) {
                return false;
            }

            foreach ($excludePatterns as $pattern) {
                if (!is_string($pattern) || $pattern === '') {
                    continue;
                }
                $inner = $pattern;
                if (strlen($pattern) > 2 && $pattern[0] === $pattern[strlen($pattern) - 1]) {
                    $inner = substr($pattern, 1, -1);
                }
                if (!str_starts_with($inner, '^')) {
                    $inner = '^' . $inner;
                }
                if (preg_match('#' . $inner . '#', $relativePath)) {
                    return false;
                }
            }

            foreach ($excludeFiles as $excluded) {
                if (!is_string($excluded) || $excluded === '') {
                    continue;
                }
                if ($relativePath === $excluded || str_starts_with($relativePath, $excluded . '/')) {
                    return false;
                }
            }

            return true;
        });

        $phar->buildFromIterator($filtered, $this->projectDir);
        $phar->setStub($this->generateStub($buildConfig, $pharFilename));
        $phar->stopBuffering();

        unset($phar);

        return $pharPath;
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
     * @param mixed[] $buildConfig
     */
    public function generateStub(array $buildConfig, string $pharAlias = 'app.phar'): string
    {
        $runtimeEnv = \CrazyGoat\WorkermanBundle\Runtime::class;
        $kernelClass = \is_string($buildConfig['kernel_class'] ?? null) && $buildConfig['kernel_class'] !== ''
            ? $buildConfig['kernel_class']
            : 'App\\Kernel';

        return <<<"PHP"
#!/usr/bin/env php
<?php

define('IN_PHAR', true);
Phar::mapPhar('{$pharAlias}');

\$runtimeDir = isset(\$_SERVER['WORKERMAN_RUNTIME_DIR']) && \$_SERVER['WORKERMAN_RUNTIME_DIR'] !== ''
    ? rtrim((string) \$_SERVER['WORKERMAN_RUNTIME_DIR'], '/')
    : dirname(Phar::running(false));

\$_SERVER['APP_RUNTIME'] = '{$runtimeEnv}';
\$_ENV['APP_CACHE_DIR'] = \$runtimeDir . '/var/cache';
\$_ENV['APP_LOG_DIR'] = \$runtimeDir . '/var/log';

foreach (['/var/cache', '/var/log', '/var/run'] as \$sub) {
    \$dir = \$runtimeDir . \$sub;
    if (!is_dir(\$dir) && !mkdir(\$dir, 0755, true) && !is_dir(\$dir)) {
        fwrite(STDERR, sprintf('Unable to create runtime directory "%s". Set WORKERMAN_RUNTIME_DIR to a writable path.' . PHP_EOL, \$dir));
        exit(1);
    }
}

// Load external .env if it exists
if (file_exists(\$runtimeDir . '/.env')) {
    if (class_exists('Symfony\\Component\\Dotenv\\Dotenv')) {
        (new Symfony\\Component\\Dotenv\\Dotenv())->load(\$runtimeDir . '/.env');
    }
}

require 'phar://{$pharAlias}/vendor/autoload.php';

\$env = \$_SERVER['APP_ENV'] ?? '{$this->environment}';
\$debug = (bool)(\$_SERVER['APP_DEBUG'] ?? false);

\$kernel = new {$kernelClass}(\$env, \$debug);
\$application = new Symfony\\Bundle\\FrameworkBundle\\Console\\Application(\$kernel);
\$application->run(new Symfony\\Component\\Console\\Input\\ArgvInput());

__HALT_COMPILER();
PHP;
    }
}
