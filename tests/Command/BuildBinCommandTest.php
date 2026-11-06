<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Command;

use CrazyGoat\WorkermanBundle\Command\BuildBinCommand;
use CrazyGoat\WorkermanBundle\Command\BuildPathResolver;
use CrazyGoat\WorkermanBundle\ConfigLoader;
use CrazyGoat\WorkermanBundle\Phar\BinaryComposer;
use CrazyGoat\WorkermanBundle\Phar\PharBuilder;
use CrazyGoat\WorkermanBundle\Phar\PharCapabilities;
use CrazyGoat\WorkermanBundle\Phar\SfxDownloader;
use CrazyGoat\WorkermanBundle\Phar\SfxSourceResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class BuildBinCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/build-bin-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tempDir)) {
            return;
        }

        foreach (glob($this->tempDir . '/build/*.phar') ?: [] as $phar) {
            try {
                \Phar::unlinkArchive($phar);
            } catch (\Throwable) {
            }
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($this->tempDir);
    }

    public function testConfigureHasUnsafeNoChecksumOption(): void
    {
        $command = $this->createCommand();
        $option = $command->getDefinition()->getOption('unsafe-no-checksum');

        self::assertInstanceOf(\Symfony\Component\Console\Input\InputOption::class, $option);
        self::assertFalse($option->acceptValue());
    }

    public function testConfigureHasInsecureOption(): void
    {
        $command = $this->createCommand();
        $option = $command->getDefinition()->getOption('insecure');

        self::assertInstanceOf(\Symfony\Component\Console\Input\InputOption::class, $option);
        self::assertFalse($option->acceptValue());
    }

    public function testRemoteSfxWithoutChecksumFailsBeforeDownload(): void
    {
        $this->requirePharWritable();
        $this->createProject();
        $sfx = $this->createFakeSfx();

        $output = new BufferedOutput();
        $status = $this->createCommand()->run(
            new ArrayInput(['--sfx-url' => 'file://' . $sfx]),
            $output,
        );

        self::assertSame(1, $status);
        $display = $output->fetch();
        self::assertStringContainsString('--sfx-checksum', $display);
        self::assertFileDoesNotExist($this->tempDir . '/build/fake.sfx', 'A rejected checksum must not download the SFX');
    }

    public function testDefaultSfxUrlReportsTheResolvedPhpVersionBeforeTheChecksumGuard(): void
    {
        $this->requirePharWritable();
        $this->createProject();

        $output = new BufferedOutput();
        $status = $this->createCommand()->run(new ArrayInput([]), $output);

        self::assertSame(1, $status);
        $display = $output->fetch();
        self::assertStringContainsString('Resolved SFX for PHP', $display);
        self::assertStringContainsString('--sfx-checksum', $display);
    }

    public function testWrongChecksumFailsAndCorrectChecksumPasses(): void
    {
        $this->requirePharWritable();
        $this->createProject();
        $sfx = $this->createFakeSfx();
        $checksum = (string) hash_file('sha256', $sfx);

        $wrongOutput = new BufferedOutput();
        $wrongStatus = $this->createCommand()->run(
            new ArrayInput([
                '--sfx-url' => 'file://' . $sfx,
                '--sfx-checksum' => str_repeat('0', 64),
            ]),
            $wrongOutput,
        );
        self::assertSame(1, $wrongStatus);
        self::assertStringContainsString('SHA-256 mismatch', $wrongOutput->fetch());

        $correctOutput = new BufferedOutput();
        $correctStatus = $this->createCommand()->run(
            new ArrayInput([
                '--sfx-url' => 'file://' . $sfx,
                '--sfx-checksum' => $checksum,
            ]),
            $correctOutput,
        );
        self::assertSame(0, $correctStatus);
        self::assertStringNotContainsString('not verified', $correctOutput->fetch());
    }

    public function testUnsafeNoChecksumAllowsADownloadFromAFileUrl(): void
    {
        $this->requirePharWritable();
        $this->createProject();
        $sfx = $this->createFakeSfx();

        $output = new BufferedOutput();
        $status = $this->createCommand()->run(
            new ArrayInput([
                '--sfx-url' => 'file://' . $sfx,
                '--unsafe-no-checksum' => true,
            ]),
            $output,
        );

        self::assertSame(0, $status);
        self::assertStringContainsString('not verified', $output->fetch());
        self::assertStringStartsWith("\xfd\xf6\x69\xe6FAKE-SFX", (string) file_get_contents($this->tempDir . '/build/app.bin'));
    }

    public function testInsecureDownloadShowsTheTlsWarning(): void
    {
        $this->requirePharWritable();
        $this->createProject();

        $output = new BufferedOutput();
        $status = $this->createCommand()->run(
            new ArrayInput([
                '--sfx-url' => 'https://127.0.0.1:' . $this->allocatePort() . '/sfx',
                '--insecure' => true,
                '--unsafe-no-checksum' => true,
            ]),
            $output,
        );

        self::assertSame(1, $status);
        self::assertStringContainsString('TLS peer verification disabled', $output->fetch());
    }

    public function testLocalSfxSkipsTheDownload(): void
    {
        $this->requirePharWritable();
        $this->createProject();
        $sfx = $this->createFakeSfx();

        $output = new BufferedOutput();
        $status = $this->createCommand()->run(
            new ArrayInput(['--sfx-file' => $sfx]),
            $output,
        );

        self::assertSame(0, $status);
        self::assertStringContainsString('Using local SFX file', $output->fetch());
        self::assertStringStartsWith("\xfd\xf6\x69\xe6FAKE-SFX", (string) file_get_contents($this->tempDir . '/build/app.bin'));
    }

    public function testKernelClassIsWrittenToThePharStub(): void
    {
        $this->requirePharWritable();
        $this->createProject();
        $sfx = $this->createFakeSfx();

        $status = $this->createCommand()->run(
            new ArrayInput([
                '--sfx-file' => $sfx,
                '--kernel-class' => 'App\\CustomKernel',
            ]),
            new BufferedOutput(),
        );

        self::assertSame(0, $status);
        $phar = new \Phar($this->tempDir . '/build/app.phar');
        $stub = $phar->getStub();
        self::assertStringContainsString('App\\CustomKernel', $stub);
        self::assertStringContainsString("Phar::mapPhar('app.phar')", $stub);
        self::assertStringNotContainsString('App\\Kernel', $stub);
    }

    public function testBuilderFailureReturnsFailure(): void
    {
        $this->createProject();
        $command = $this->createCommand(new PharBuilder($this->tempDir, 'test', new PharCapabilities(true, true)));

        $output = new BufferedOutput();
        $status = $command->run(new ArrayInput([]), $output);

        self::assertSame(1, $status);
        self::assertStringContainsString('phar.readonly', $output->fetch());
    }

    private function createCommand(?PharBuilder $builder = null): BuildBinCommand
    {
        $configLoader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $configLoader->setBuildConfig($this->buildConfig());

        return new BuildBinCommand(
            $configLoader,
            $builder ?? new PharBuilder($this->tempDir, 'test'),
            new SfxDownloader(),
            new BinaryComposer(),
            new BuildPathResolver(),
            new SfxSourceResolver(),
            $this->tempDir,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildConfig(): array
    {
        return [
            'build_dir' => $this->tempDir . '/build',
            'kernel_class' => 'App\\Kernel',
            'phar_filename' => 'app.phar',
            'bin_filename' => 'app.bin',
            'bin_php_version' => null,
            'sfx' => ['url' => null, 'file' => null, 'sha256' => null, 'allow_insecure' => false],
            'exclude_patterns' => [],
            'exclude_files' => [],
            'custom_ini' => null,
        ];
    }

    private function createProject(): void
    {
        mkdir($this->tempDir . '/src', 0755, true);
        mkdir($this->tempDir . '/vendor', 0755, true);
        mkdir($this->tempDir . '/config/packages', 0755, true);
        file_put_contents($this->tempDir . '/vendor/autoload.php', '<?php // stub');
    }

    private function createFakeSfx(): string
    {
        $path = $this->tempDir . '/fake.sfx';
        file_put_contents($path, "\xfd\xf6\x69\xe6FAKE-SFX");

        return $path;
    }

    private function requirePharWritable(): void
    {
        if ((bool) ini_get('phar.readonly')) {
            self::markTestSkipped('phar.readonly is On — run with -d phar.readonly=0.');
        }
    }

    private function allocatePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    public function testResolveBinPathUsesConfigDefaults(): void
    {
        $resolver = new BuildPathResolver();
        $path = $resolver->resolveBinPath(null, '/abs/build', [
            'bin_filename' => 'app.bin',
        ]);

        self::assertSame('/abs/build/app.bin', $path);
    }

    public function testResolveBinPathPrefersCliFilename(): void
    {
        $resolver = new BuildPathResolver();
        $path = $resolver->resolveBinPath('custom.bin', '/abs/build', [
            'bin_filename' => 'app.bin',
        ]);

        self::assertSame('/abs/build/custom.bin', $path);
    }

    public function testResolvePharPathRespectsPharFilenameOption(): void
    {
        $resolver = new BuildPathResolver();
        $path = $resolver->resolvePharPath('mid.phar', '/abs/build', [
            'phar_filename' => 'app.phar',
        ]);

        self::assertSame('/abs/build/mid.phar', $path);
    }
}
