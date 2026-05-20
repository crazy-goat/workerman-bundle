<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'workerman:build:bin', description: 'Build a standalone binary of the Symfony application (PHAR + static PHP)')]
class BuildBinCommand extends Command
{
    private const DEFAULT_SFX_URL = 'https://download.workerman.net/php/php%s.micro.sfx';

    private const MAGIC_BYTES = "\xfd\xf6\x69\xe6";

    public function __construct(
        private readonly BuildPharCommand $buildPharCommand,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Output directory for the binary')
            ->addOption('filename', null, InputOption::VALUE_REQUIRED, 'Name of the generated binary')
            ->addOption('sfx-file', null, InputOption::VALUE_REQUIRED, 'Local path to phpmicro.sfx binary')
            ->addOption('sfx-url', null, InputOption::VALUE_REQUIRED, 'URL to download phpmicro.sfx from')
            ->addOption('php-version', null, InputOption::VALUE_REQUIRED, 'PHP version for the static binary (e.g. 8.3)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Step 1: Build the PHAR first
        $io->section('Step 1/4: Building PHAR');

        $pharResult = $this->buildPharCommand->execute($input, $output);
        if ($pharResult !== Command::SUCCESS) {
            // Errors already printed by BuildPharCommand
            return $pharResult;
        }

        // Determine output paths (mirror BuildPharCommand logic)
        $buildConfig = $this->buildPharCommand->getConfigLoader()->getBuildConfig();
        $projectDir = $this->buildPharCommand->getProjectDir();

        $buildDir = $input->getOption('output-dir') ?: $buildConfig['build_dir'] ?? $projectDir . '/build';
        if (!is_string($buildDir)) {
            $io->error('Invalid build directory.');

            return Command::FAILURE;
        }
        if (!str_starts_with($buildDir, '/')) {
            $buildDir = $projectDir . '/' . $buildDir;
        }

        $pharFilename = $input->getOption('filename') ?: $buildConfig['phar_filename'] ?? 'app.phar';
        if (!is_string($pharFilename)) {
            $pharFilename = 'app.phar';
        }
        $binFilename = $buildConfig['bin_filename'] ?? 'app.bin';
        if (!is_string($binFilename)) {
            $binFilename = 'app.bin';
        }

        $pharPath = $buildDir . '/' . $pharFilename;
        $binPath = $buildDir . '/' . $binFilename;

        // Step 2: Obtain phpmicro.sfx
        $io->section('Step 2/4: Obtaining phpmicro.sfx');

        $sfxPath = $this->resolveSfxPath($input, $buildConfig, $buildDir, $io);
        if ($sfxPath === null) {
            return Command::FAILURE;
        }

        // Step 3: Build the binary
        $io->section('Step 3/4: Building standalone binary');

        $this->buildBinary($sfxPath, $pharPath, $binPath, $buildConfig['custom_ini'] ?? null, $io);

        // Step 4: Verify
        $io->section('Step 4/4: Verifying');
        $binSize = filesize($binPath);
        $io->success(sprintf(
            'Standalone binary built: %s (%s)',
            $binPath,
            $this->formatSize($binSize),
        ));

        $io->note(sprintf(
            'To run: ./%s workerman:server start  (or any other console command)',
            $binFilename,
        ));

        return Command::SUCCESS;
    }

    /**
     * @param mixed[] $buildConfig
     */
    private function resolveSfxPath(InputInterface $input, array $buildConfig, string $buildDir, SymfonyStyle $io): ?string
    {
        // Priority: CLI option --sfx-file > config sfx.file > CLI option --sfx-url > config sfx.url > default URL

        $sfxFile = $input->getOption('sfx-file');
        if (is_string($sfxFile) && $sfxFile !== '') {
            if (!is_file($sfxFile)) {
                $io->error(sprintf('SFX file not found: %s', $sfxFile));

                return null;
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

        if (is_string($sfxUrl) && $sfxUrl !== '') {
            return $this->downloadSfx($sfxUrl, $buildDir, $io);
        }

        // Default: download from webman mirror
        $phpVersion = $input->getOption('php-version');
        if (!is_string($phpVersion) || $phpVersion === '') {
            $phpVersion = $buildConfig['bin_php_version'] ?? null;
        }
        if (!is_string($phpVersion) || $phpVersion === '') {
            $phpVersion = sprintf('%s.%s', PHP_MAJOR_VERSION, PHP_MINOR_VERSION);
        }

        $defaultUrl = sprintf(self::DEFAULT_SFX_URL, $phpVersion);
        $io->text(sprintf('Downloading phpmicro.sfx for PHP %s from default mirror...', $phpVersion));

        return $this->downloadSfx($defaultUrl, $buildDir, $io);
    }

    private function downloadSfx(string $url, string $buildDir, SymfonyStyle $io): ?string
    {
        $io->text(sprintf('Downloading: %s', $url));

        $parts = explode('/', $url);
        $filename = end($parts);
        $destination = $buildDir . '/' . $filename;

        if (is_file($destination)) {
            $io->text(sprintf('Already downloaded: %s', $destination));

            return $destination;
        }

        // Also check for .zip variant
        $supportZip = class_exists(\ZipArchive::class);
        $zipDestination = $destination . '.zip';

        if (is_file($zipDestination)) {
            $io->text(sprintf('Already downloaded: %s', $zipDestination));
        } else {
            $content = $this->httpDownload($url, $io);
            if ($content === null) {
                // Try .zip variant
                if ($supportZip) {
                    $io->text('Trying .zip variant...');
                    $content = $this->httpDownload($url . '.zip', $io);
                    if ($content === null) {
                        return null;
                    }
                    file_put_contents($zipDestination, $content);
                } else {
                    return null;
                }
            } else {
                file_put_contents($destination, $content);
            }
        }

        // Unzip if needed
        if ($supportZip && is_file($zipDestination) && !is_file($destination)) {
            $io->text('Extracting SFX from zip...');
            $zip = new \ZipArchive();
            $zip->open($zipDestination, \ZipArchive::CHECKCONS);
            $zip->extractTo($buildDir);
            $zip->close();

            if (!is_file($destination)) {
                // The extracted file might have a different name - find it
                $sfxFile = $buildDir . '/' . str_replace('.zip', '', $filename);
                if (is_file($sfxFile)) {
                    $destination = $sfxFile;
                } else {
                    $io->error('Failed to extract SFX from zip.');

                    return null;
                }
            }
        }

        if (!is_file($destination)) {
            $io->error('Failed to obtain phpmicro.sfx.');

            return null;
        }

        $io->text(sprintf('SFX ready: %s', $destination));

        return $destination;
    }

    private function httpDownload(string $url, SymfonyStyle $io): ?string
    {
        $context = null;
        if (extension_loaded('openssl') && str_starts_with($url, 'https://')) {
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);
        }

        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            $io->warning(sprintf('Failed to download: %s', $url));

            return null;
        }

        return $result;
    }

    private function buildBinary(string $sfxPath, string $pharPath, string $binPath, string|null $customIni, SymfonyStyle $io): void
    {
        if (file_exists($binPath)) {
            unlink($binPath);
        }

        // 1. Write SFX binary
        $sfxContent = file_get_contents($sfxPath);
        file_put_contents($binPath, $sfxContent);
        unset($sfxContent);

        // 2. Write custom INI header (if configured)
        if (is_string($customIni) && $customIni !== '') {
            $io->text('Embedding custom php.ini directives...');

            $headerPath = $binPath . '.iniheader.tmp';
            $f = fopen($headerPath, 'wb');
            fwrite($f, self::MAGIC_BYTES);
            fwrite($f, pack('N', strlen($customIni)));
            fwrite($f, $customIni);
            fclose($f);

            file_put_contents($binPath, file_get_contents($headerPath), FILE_APPEND);
            unlink($headerPath);
            unset($headerPath);
        }

        // 3. Append PHAR payload
        file_put_contents($binPath, file_get_contents($pharPath), FILE_APPEND);

        // 4. Make executable
        chmod($binPath, 0755);
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
