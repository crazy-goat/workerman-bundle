<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Command;

use CrazyGoat\WorkermanBundle\ConfigLoader;
use CrazyGoat\WorkermanBundle\Phar\BinaryComposer;
use CrazyGoat\WorkermanBundle\Phar\ByteFormatter;
use CrazyGoat\WorkermanBundle\Phar\PharBuilder;
use CrazyGoat\WorkermanBundle\Phar\SfxDownloader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'workerman:build:bin', description: 'Build a standalone binary of the Symfony application (PHAR + static PHP)')]
final class BuildBinCommand extends Command
{
    private const DEFAULT_SFX_URL = 'https://download.workerman.net/php/php%s.micro.sfx';

    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly PharBuilder $pharBuilder,
        private readonly SfxDownloader $sfxDownloader,
        private readonly BinaryComposer $binaryComposer,
        private readonly BuildPathResolver $pathResolver,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Output directory for the binary')
            ->addOption('filename', null, InputOption::VALUE_REQUIRED, 'Name of the generated binary')
            ->addOption('phar-filename', null, InputOption::VALUE_REQUIRED, 'Name of the intermediate PHAR file')
            ->addOption('kernel-class', null, InputOption::VALUE_REQUIRED, 'Kernel class to use in the PHAR stub')
            ->addOption('sfx-file', null, InputOption::VALUE_REQUIRED, 'Local path to phpmicro.sfx binary')
            ->addOption('sfx-url', null, InputOption::VALUE_REQUIRED, 'URL to download phpmicro.sfx from')
            ->addOption('sfx-checksum', null, InputOption::VALUE_REQUIRED, 'Expected SHA-256 of the SFX binary (hex)')
            ->addOption('php-version', null, InputOption::VALUE_REQUIRED, 'PHP version for the static binary (e.g. 8.3)')
            ->addOption('insecure', null, InputOption::VALUE_NONE, 'Disable TLS peer verification when downloading the SFX (not recommended)')
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

        try {
            $buildDir = $this->pathResolver->resolveBuildDir($input->getOption('output-dir'), $buildConfig, $this->projectDir);
            $pharPath = $this->pathResolver->resolvePharPath($input->getOption('phar-filename'), $buildDir, $buildConfig);
            $binPath = $this->pathResolver->resolveBinPath($input->getOption('filename'), $buildDir, $buildConfig);

            $io->section('Step 1/3: Building PHAR');
            $this->pharBuilder->build($buildConfig, $pharPath);
            $pharSize = filesize($pharPath);
            $io->text(sprintf('PHAR: %s (%s)', $pharPath, ByteFormatter::format(is_int($pharSize) ? $pharSize : 0)));

            $io->section('Step 2/3: Obtaining phpmicro.sfx');
            $sfxPath = $this->resolveSfx($input, $buildConfig, dirname($binPath), $io);
            $io->text(sprintf('SFX ready: %s', $sfxPath));

            $io->section('Step 3/3: Composing standalone binary');
            $customIni = isset($buildConfig['custom_ini']) && is_string($buildConfig['custom_ini'])
                ? $buildConfig['custom_ini']
                : null;
            $this->binaryComposer->compose($sfxPath, $pharPath, $binPath, $customIni);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $binSize = filesize($binPath);
        $io->success(sprintf(
            'Standalone binary built: %s (%s)',
            $binPath,
            ByteFormatter::format(is_int($binSize) ? $binSize : 0),
        ));
        $io->note(sprintf('To run: ./%s workerman:server start', basename($binPath)));

        return Command::SUCCESS;
    }

    /**
     * @param mixed[] $buildConfig
     */
    private function resolveSfx(InputInterface $input, array $buildConfig, string $downloadDir, SymfonyStyle $io): string
    {
        // Priority: --sfx-file > sfx.file > --sfx-url > sfx.url > default mirror
        $sfxFile = $input->getOption('sfx-file');
        if (is_string($sfxFile) && $sfxFile !== '') {
            if (!is_file($sfxFile)) {
                throw new \RuntimeException(sprintf('SFX file not found: %s', $sfxFile));
            }
            $io->text(sprintf('Using local SFX file: %s', $sfxFile));

            return $sfxFile;
        }

        $configSfxFile = $buildConfig['sfx']['file'] ?? null;
        if (is_string($configSfxFile) && $configSfxFile !== '' && is_file($configSfxFile)) {
            $io->text(sprintf('Using SFX file from config: %s', $configSfxFile));

            return $configSfxFile;
        }

        $sfxUrl = $input->getOption('sfx-url');
        if (!is_string($sfxUrl) || $sfxUrl === '') {
            $sfxUrl = $buildConfig['sfx']['url'] ?? null;
        }

        if (!is_string($sfxUrl) || $sfxUrl === '') {
            $phpVersion = $input->getOption('php-version');
            if (!is_string($phpVersion) || $phpVersion === '') {
                $phpVersion = $buildConfig['bin_php_version'] ?? null;
            }
            if (!is_string($phpVersion) || $phpVersion === '') {
                $phpVersion = sprintf('%s.%s', PHP_MAJOR_VERSION, PHP_MINOR_VERSION);
            }
            $sfxUrl = sprintf(self::DEFAULT_SFX_URL, $phpVersion);
            $io->text(sprintf('Resolved SFX for PHP %s', $phpVersion));
        }

        $checksum = $input->getOption('sfx-checksum');
        if (!is_string($checksum) || $checksum === '') {
            $checksum = $buildConfig['sfx']['sha256'] ?? null;
        }
        if (!is_string($checksum) || $checksum === '') {
            $checksum = null;
        }

        $allowInsecure = (bool) $input->getOption('insecure') || (bool) ($buildConfig['sfx']['allow_insecure'] ?? false);
        if ($allowInsecure) {
            $io->warning('Downloading SFX with TLS peer verification disabled. This is unsafe — provide --sfx-checksum or use a trusted mirror.');
        }
        if ($checksum === null) {
            $io->warning('No SFX SHA-256 configured. The downloaded binary is not verified. Set build.sfx.sha256 (or pass --sfx-checksum) to enable verification.');
        }

        $io->text(sprintf('Downloading: %s', $sfxUrl));

        return $this->sfxDownloader->fetch($sfxUrl, $downloadDir, $checksum, $allowInsecure);
    }
}
