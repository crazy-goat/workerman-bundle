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

        // Build the exclusion regex pattern
        $excludePatterns = $buildConfig['exclude_patterns'] ?? [];
        $excludeFiles = $buildConfig['exclude_files'] ?? [];

        $excludePattern = $this->buildExclusionPattern(
            is_array($excludePatterns) ? $excludePatterns : [],
            is_array($excludeFiles) ? $excludeFiles : [],
        );

        $io->text(sprintf('Exclusion pattern: %s', $excludePattern));

        $phar->buildFromDirectory($this->projectDir, $excludePattern);

        $io->text('Files collected, generating stub...');

        $phar->setStub($this->generateStub($buildConfig));

        $phar->stopBuffering();

        unset($phar);

        $pharSize = filesize($pharPath);
        $io->success(sprintf('PHAR archive built: %s (%s)', $pharPath, $this->formatSize(is_int($pharSize) ? $pharSize : 0)));

        return Command::SUCCESS;
    }

    /**
     * @param string[] $excludePatterns
     * @param string[] $excludeFiles
     */
    protected function buildExclusionPattern(array $excludePatterns, array $excludeFiles): string
    {
        // Standard patterns: build dir, vendor build artifacts, VCS, dev tools
        $patterns = [
            '#^build/#',
            '#^\.git/#',
            '#^\.github/#',
            '#^tests/#',
            '#^docs/#',
            '#phpunit\.xml#',
            '#\.php-cs-fixer#',
            '#phpstan\.neon#',
            '#rector\.php#',
            // Exclude the PHAR/binary files from the archive itself
            '#\.phar$#',
            '#\.bin$#',
            // Env files handled separately (live outside PHAR)
            '#^\.env#',
        ];

        foreach ($excludePatterns as $pattern) {
            $patterns[] = $pattern;
        }

        foreach ($excludeFiles as $file) {
            $patterns[] = '#^' . preg_quote($file, '#') . '#';
        }

        return implode('|', $patterns);
    }

    /**
     * @param mixed[] $buildConfig
     */
    protected function generateStub(array $buildConfig): string
    {
        $runtimeEnv = \CrazyGoat\WorkermanBundle\Runtime::class;
        $kernelClass = \is_string($buildConfig['kernel_class'] ?? null) && $buildConfig['kernel_class'] !== ''
            ? $buildConfig['kernel_class']
            : 'App\\Kernel';
        $pharAlias = \is_string($buildConfig['phar_filename'] ?? null) && $buildConfig['phar_filename'] !== ''
            ? basename($buildConfig['phar_filename'], '.phar')
            : 'app';

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
