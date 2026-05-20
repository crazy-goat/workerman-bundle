<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Command;

use CrazyGoat\WorkermanBundle\ConfigLoader;
use CrazyGoat\WorkermanBundle\Phar\ByteFormatter;
use CrazyGoat\WorkermanBundle\Phar\PharBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'workerman:build:phar', description: 'Build a PHAR archive of the Symfony application')]
final class BuildPharCommand extends Command
{
    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly PharBuilder $pharBuilder,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Output directory for the PHAR file')
            ->addOption('filename', null, InputOption::VALUE_REQUIRED, 'Name of the generated PHAR file')
            ->addOption('kernel-class', null, InputOption::VALUE_REQUIRED, 'Kernel class to use in the PHAR stub')
            ->addOption('include-tests', null, InputOption::VALUE_NONE, 'Include tests/ directory in the PHAR (for testing only)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $buildConfig = $this->configLoader->getBuildConfig();

        $kernelClassOption = $input->getOption('kernel-class');
        if (is_string($kernelClassOption) && $kernelClassOption !== '') {
            $buildConfig['kernel_class'] = $kernelClassOption;
        }

        $workermanConfig = $this->configLoader->getWorkermanConfig();
        $fileMonitorActive = $workermanConfig['reload_strategy']['file_monitor']['active'] ?? false;
        if ($fileMonitorActive) {
            $io->warning('File monitor reload strategy is enabled but will be disabled at runtime when running from PHAR. Consider disabling it in your config.');
        }

        try {
            $pharPath = self::resolvePharPath($input, $buildConfig, $this->projectDir);
            $includeTests = (bool) $input->getOption('include-tests');

            $io->section('Building PHAR archive');
            $this->pharBuilder->build($buildConfig, $pharPath, $includeTests);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $pharSize = filesize($pharPath);
        $io->success(sprintf('PHAR archive built: %s (%s)', $pharPath, ByteFormatter::format(is_int($pharSize) ? $pharSize : 0)));

        return Command::SUCCESS;
    }

    /**
     * @param mixed[] $buildConfig
     *
     * @throws \RuntimeException when build_dir/phar_filename are invalid
     */
    public static function resolvePharPath(InputInterface $input, array $buildConfig, string $projectDir): string
    {
        $buildDir = $input->getOption('output-dir') ?: $buildConfig['build_dir'] ?? $projectDir . '/build';
        $pharFilename = $input->getOption('filename') ?: $buildConfig['phar_filename'] ?? 'app.phar';

        if (!is_string($buildDir) || $buildDir === '' || !is_string($pharFilename) || $pharFilename === '') {
            throw new \RuntimeException('Invalid build configuration: build_dir and phar_filename must be non-empty strings.');
        }

        if (!str_starts_with($buildDir, '/')) {
            $buildDir = $projectDir . '/' . $buildDir;
        }

        return $buildDir . '/' . $pharFilename;
    }
}
