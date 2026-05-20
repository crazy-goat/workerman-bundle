<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Command;

use CrazyGoat\WorkermanBundle\ConfigLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'workerman:build:phar', description: 'Build a PHAR archive of the Symfony application')]
class BuildPharCommand extends Command
{
    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly string $projectDir,
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Output directory for the PHAR file')
            ->addOption('filename', null, InputOption::VALUE_REQUIRED, 'Name of the generated PHAR file')
            ->addOption('kernel-class', null, InputOption::VALUE_REQUIRED, 'Kernel class to use in the PHAR stub')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (ini_get('phar.readonly') === '1' || ini_get('phar.readonly') === 'On') {
            $io->error('phar.readonly must be disabled in php.ini. Set phar.readonly=0 and try again.');

            return Command::FAILURE;
        }

        if (!class_exists(\Phar::class)) {
            $io->error('The Phar extension is required to build PHAR archives.');

            return Command::FAILURE;
        }

        $buildConfig = $this->configLoader->getBuildConfig();

        // CLI --kernel-class overrides config value
        $kernelClassOption = $input->getOption('kernel-class');
        if (is_string($kernelClassOption) && $kernelClassOption !== '') {
            $buildConfig['kernel_class'] = $kernelClassOption;
        }

        $workermanConfig = $this->configLoader->getWorkermanConfig();

        // Warn if file_monitor is enabled — it's useless in a PHAR (files are frozen)
        $fileMonitorActive = $workermanConfig['reload_strategy']['file_monitor']['active'] ?? false;
        if ($fileMonitorActive) {
            $io->warning('File monitor reload strategy is enabled but will be disabled at runtime when running from PHAR. Consider disabling it in your config.');
        }

        $buildDir = $input->getOption('output-dir') ?: $buildConfig['build_dir'] ?? $this->projectDir . '/build';
        $pharFilename = $input->getOption('filename') ?: $buildConfig['phar_filename'] ?? 'app.phar';

        if (!is_string($buildDir) || !is_string($pharFilename)) {
            $io->error('Invalid build configuration.');

            return Command::FAILURE;
        }

        // Resolve build dir relative to project dir
        if (!str_starts_with($buildDir, '/')) {
            $buildDir = $this->projectDir . '/' . $buildDir;
        }

        if (!is_dir($buildDir) && !mkdir($buildDir, 0755, true)) {
            $io->error(sprintf('Unable to create build directory "%s".', $buildDir));

            return Command::FAILURE;
        }

        $pharPath = $buildDir . '/' . $pharFilename;

        if (file_exists($pharPath)) {
            unlink($pharPath);
        }

        $io->section('Building PHAR archive');

        $phar = new \Phar($pharPath, 0, $pharFilename);
        $phar->startBuffering();

        $excludePatterns = $buildConfig['exclude_patterns'] ?? [];
        $excludeFiles = $buildConfig['exclude_files'] ?? [];

        // Build the set of files to include (everything except excluded patterns)
        $directory = new \RecursiveDirectoryIterator(
            $this->projectDir,
            \RecursiveDirectoryIterator::SKIP_DOTS
        );
        $iterator = new \RecursiveIteratorIterator($directory);

        $excludePatterns = is_array($excludePatterns) ? $excludePatterns : [];
        $excludeFiles = is_array($excludeFiles) ? $excludeFiles : [];

        $filtered = new \CallbackFilterIterator($iterator, function (\SplFileInfo $file) use ($excludePatterns, $excludeFiles): bool {
            $relativePath = str_replace(
                [$this->projectDir . '/', $this->projectDir],
                '',
                $file->getPathname()
            );
            $relativePath = ltrim($relativePath, '/');

            if ($relativePath === '') {
                return false;
            }

            // Check against built-in exclusion patterns
            if ($this->isExcluded($relativePath)) {
                return false;
            }

            // Check against config exclude_patterns
            foreach ($excludePatterns as $pattern) {
                // Strip delimiters if present
                $inner = $pattern;
                if (strlen($pattern) > 2 && $pattern[0] === $pattern[strlen($pattern) - 1]) {
                    $inner = substr($pattern, 1, -1);
                }
                // Ensure ^ prefix for matching from start of relative path
                if (!str_starts_with($inner, '^')) {
                    $inner = '^' . $inner;
                }
                if (preg_match('#' . $inner . '#', $relativePath)) {
                    return false;
                }
            }

            // Check against config exclude_files (exact match relative to project root)
            foreach ($excludeFiles as $file) {
                if ($relativePath === $file || str_starts_with($relativePath, $file . '/')) {
                    return false;
                }
            }

            return true;
        });

        $phar->buildFromIterator($filtered, $this->projectDir);

        $io->text('Files collected, generating stub...');

        $phar->setStub($this->generateStub($buildConfig, $pharFilename));

        $phar->stopBuffering();

        unset($phar);

        $pharSize = filesize($pharPath);
        $io->success(sprintf('PHAR archive built: %s (%s)', $pharPath, $this->formatSize(is_int($pharSize) ? $pharSize : 0)));

        return Command::SUCCESS;
    }

    /**
     * Check if a relative path should be excluded from the PHAR archive.
     */
    protected function isExcluded(string $relativePath): bool
    {
        // Standard patterns: build dir, VCS, dev tools, cache, etc.
        if (preg_match('#^(build/|\\.git/|\\.github/|tests/|docs/|var/)#', $relativePath)) {
            return true;
        }

        // Config/dev files at the root
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
    protected function generateStub(array $buildConfig, string $pharAlias = 'app.phar'): string
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

\$runtimeDir = dirname(Phar::running(false));

\$_SERVER['APP_RUNTIME'] = '{$runtimeEnv}';
\$_ENV['APP_CACHE_DIR'] = \$runtimeDir . '/var/cache';
\$_ENV['APP_LOG_DIR'] = \$runtimeDir . '/var/log';

// Create runtime directories
@mkdir(\$runtimeDir . '/var/cache', 0755, true);
@mkdir(\$runtimeDir . '/var/log', 0755, true);
@mkdir(\$runtimeDir . '/var/run', 0755, true);

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

    public function getConfigLoader(): ConfigLoader
    {
        return $this->configLoader;
    }

    public function getProjectDir(): string
    {
        return $this->projectDir;
    }

    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
