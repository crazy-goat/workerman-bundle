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
        private readonly BuildPathResolver $pathResolver,
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
            $buildDir = $this->pathResolver->resolveBuildDir($input->getOption('output-dir'), $buildConfig, $this->projectDir);
            $pharPath = $this->pathResolver->resolvePharPath($input->getOption('filename'), $buildDir, $buildConfig);
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

}
