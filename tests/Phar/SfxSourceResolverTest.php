<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Phar;

use CrazyGoat\WorkermanBundle\Phar\SfxSourceResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

final class SfxSourceResolverTest extends TestCase
{
    private SfxSourceResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new SfxSourceResolver();
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function createInput(array $parameters = []): ArrayInput
    {
        return new ArrayInput($parameters, new InputDefinition([
            new InputOption('sfx-file', null, InputOption::VALUE_REQUIRED),
            new InputOption('sfx-url', null, InputOption::VALUE_REQUIRED),
            new InputOption('sfx-checksum', null, InputOption::VALUE_REQUIRED),
            new InputOption('php-version', null, InputOption::VALUE_REQUIRED),
            new InputOption('insecure', null, InputOption::VALUE_NONE),
        ]));
    }

    public function testResolvesSfxFileFromCliOption(): void
    {
        $input = $this->createInput(['--sfx-file' => __FILE__]);
        $source = $this->resolver->resolve($input, []);

        self::assertTrue($source->isLocal());
        self::assertSame(__FILE__, $source->localPath);
        self::assertNull($source->url);
        self::assertNull($source->checksum);
        self::assertFalse($source->allowInsecure);
    }

    public function testThrowsWhenSfxFileOptionDoesNotExist(): void
    {
        $input = $this->createInput(['--sfx-file' => '/nonexistent/path/file.sfx']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SFX file not found');

        $this->resolver->resolve($input, []);
    }

    public function testResolvesSfxFileFromConfigWhenCliOptionAbsent(): void
    {
        $input = $this->createInput();
        $source = $this->resolver->resolve($input, [
            'sfx' => ['file' => __FILE__],
        ]);

        self::assertTrue($source->isLocal());
        self::assertSame(__FILE__, $source->localPath);
    }

    public function testConfigSfxFileIgnoredWhenNotAFile(): void
    {
        $input = $this->createInput();
        $source = $this->resolver->resolve($input, [
            'sfx' => ['file' => '/nonexistent/file'],
        ]);

        self::assertFalse($source->isLocal());
        self::assertNotNull($source->url);
    }

    public function testResolvesSfxUrlFromCliOption(): void
    {
        $input = $this->createInput(['--sfx-url' => 'https://example.com/custom.sfx']);
        $source = $this->resolver->resolve($input, []);

        self::assertFalse($source->isLocal());
        self::assertSame('https://example.com/custom.sfx', $source->url);
    }

    public function testResolvesSfxUrlFromConfigWhenCliOptionAbsent(): void
    {
        $input = $this->createInput();
        $source = $this->resolver->resolve($input, [
            'sfx' => ['url' => 'https://example.com/config.sfx'],
        ]);

        self::assertSame('https://example.com/config.sfx', $source->url);
    }

    public function testResolvesDefaultSfxUrlFromPhpVersionCliOption(): void
    {
        $input = $this->createInput(['--php-version' => '8.3']);
        $source = $this->resolver->resolve($input, []);

        self::assertNotNull($source->url);
        self::assertStringContainsString('8.3', $source->url);
        self::assertStringContainsString('download.workerman.net', $source->url);
    }

    public function testResolvesDefaultSfxUrlFromConfigPhpVersion(): void
    {
        $input = $this->createInput();
        $source = $this->resolver->resolve($input, [
            'bin_php_version' => '8.2',
        ]);

        self::assertNotNull($source->url);
        self::assertStringContainsString('8.2', $source->url);
    }

    public function testResolvesDefaultSfxUrlFromRunningPhpVersion(): void
    {
        $input = $this->createInput();
        $source = $this->resolver->resolve($input, []);

        self::assertNotNull($source->url);
        $expectedVersion = sprintf('%s.%s', PHP_MAJOR_VERSION, PHP_MINOR_VERSION);
        self::assertStringContainsString($expectedVersion, $source->url);
    }

    public function testResolvesChecksumFromCliOption(): void
    {
        $input = $this->createInput([
            '--sfx-checksum' => 'abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890',
        ]);
        $source = $this->resolver->resolve($input, []);

        self::assertSame('abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890', $source->checksum);
    }

    public function testResolvesChecksumFromConfigWhenCliOptionAbsent(): void
    {
        $input = $this->createInput();
        $source = $this->resolver->resolve($input, [
            'sfx' => ['sha256' => 'configchecksum1234567890abcdef1234567890abcdef1234567890abcdef1234'],
        ]);

        self::assertSame('configchecksum1234567890abcdef1234567890abcdef1234567890abcdef1234', $source->checksum);
    }

    public function testResolvesChecksumToNullWhenNeitherCliNorConfigSet(): void
    {
        $input = $this->createInput();
        $source = $this->resolver->resolve($input, []);

        self::assertNull($source->checksum);
    }

    public function testResolvesAllowInsecureFromCliOption(): void
    {
        $input = $this->createInput(['--insecure' => true]);
        $source = $this->resolver->resolve($input, []);

        self::assertTrue($source->allowInsecure);
    }

    public function testResolvesAllowInsecureFromConfig(): void
    {
        $input = $this->createInput();
        $source = $this->resolver->resolve($input, [
            'sfx' => ['allow_insecure' => true],
        ]);

        self::assertTrue($source->allowInsecure);
    }

    public function testResolvesAllowInsecureToFalseByDefault(): void
    {
        $input = $this->createInput();
        $source = $this->resolver->resolve($input, []);

        self::assertFalse($source->allowInsecure);
    }

    public function testCliOptionTakesPriorityOverConfig(): void
    {
        $input = $this->createInput([
            '--sfx-url' => 'https://example.com/cli.sfx',
            '--sfx-checksum' => 'cli-check',
            '--insecure' => true,
        ]);
        $source = $this->resolver->resolve($input, [
            'sfx' => [
                'url' => 'https://example.com/config.sfx',
                'sha256' => 'config-check',
                'allow_insecure' => false,
            ],
        ]);

        self::assertSame('https://example.com/cli.sfx', $source->url);
        self::assertSame('cli-check', $source->checksum);
        self::assertTrue($source->allowInsecure);
    }

    public function testResolveSfxUrlReturnsCliUrlOverConfig(): void
    {
        $input = $this->createInput(['--sfx-url' => 'https://cli.example.com/sfx']);
        $url = $this->resolver->resolveSfxUrl($input, [
            'sfx' => ['url' => 'https://config.example.com/sfx'],
        ]);

        self::assertSame('https://cli.example.com/sfx', $url);
    }

    public function testResolveChecksumReturnsCliOverConfig(): void
    {
        $input = $this->createInput(['--sfx-checksum' => 'cli-checksum']);
        $checksum = $this->resolver->resolveChecksum($input, [
            'sfx' => ['sha256' => 'config-checksum'],
        ]);

        self::assertSame('cli-checksum', $checksum);
    }

    public function testResolveAllowInsecureReturnsTrueWhenCliSet(): void
    {
        $input = $this->createInput(['--insecure' => true]);
        $allow = $this->resolver->resolveAllowInsecure($input, []);

        self::assertTrue($allow);
    }
}
