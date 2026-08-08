<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\ConfigLoader;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class ConfigLoaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/config-loader-test-' . uniqid();
        mkdir($this->tempDir . '/config/packages', 0777, true);
        mkdir($this->tempDir . '/cache', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($dir);
    }

    public function testWarmUpThrowsExceptionWhenWorkermanConfigIsMissing(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        // Set only process, scheduler, and build config
        $loader->setProcessConfig(['some' => 'process']);
        $loader->setSchedulerConfig(['some' => 'scheduler']);
        $loader->setBuildConfig(['some' => 'build']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('All config sections must be set before warming up. Missing: workerman');

        $loader->warmUp($this->tempDir . '/cache');
    }

    public function testWarmUpThrowsExceptionWhenProcessConfigIsMissing(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        // Set only workerman, scheduler, and build config
        $loader->setWorkermanConfig(['some' => 'workerman']);
        $loader->setSchedulerConfig(['some' => 'scheduler']);
        $loader->setBuildConfig(['some' => 'build']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('All config sections must be set before warming up. Missing: process');

        $loader->warmUp($this->tempDir . '/cache');
    }

    public function testWarmUpThrowsExceptionWhenSchedulerConfigIsMissing(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        // Set only workerman, process, and build config
        $loader->setWorkermanConfig(['some' => 'workerman']);
        $loader->setProcessConfig(['some' => 'process']);
        $loader->setBuildConfig(['some' => 'build']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('All config sections must be set before warming up. Missing: scheduler');

        $loader->warmUp($this->tempDir . '/cache');
    }

    public function testWarmUpThrowsExceptionWhenBuildConfigIsMissing(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        // Set only workerman, process, and scheduler config
        $loader->setWorkermanConfig(['some' => 'workerman']);
        $loader->setProcessConfig(['some' => 'process']);
        $loader->setSchedulerConfig(['some' => 'scheduler']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('All config sections must be set before warming up. Missing: build');

        $loader->warmUp($this->tempDir . '/cache');
    }

    public function testWarmUpThrowsExceptionWithMultipleMissingSections(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        // Set only workerman config
        $loader->setWorkermanConfig(['some' => 'workerman']);
        $loader->setBuildConfig(['some' => 'build']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('All config sections must be set before warming up. Missing: process, scheduler');

        $loader->warmUp($this->tempDir . '/cache');
    }

    public function testWarmUpThrowsExceptionWhenNoConfigIsSet(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        // Don't set any config

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('All config sections must be set before warming up. Missing: workerman, process, scheduler, build');

        $loader->warmUp($this->tempDir . '/cache');
    }

    public function testWarmUpSucceedsWithAllConfigSectionsSet(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        // Set all config sections
        $loader->setWorkermanConfig(['server' => ['listen' => 'http://0.0.0.0:8080']]);
        $loader->setProcessConfig(['processes' => []]);
        $loader->setSchedulerConfig(['schedules' => []]);
        $loader->setBuildConfig(['build_dir' => '/tmp/build']);

        // Should not throw exception
        $result = $loader->warmUp($this->tempDir . '/cache');

        // Verify cache was created
        $this->assertFileExists($this->tempDir . '/cache/workerman/config.cache.php');
        $this->assertSame([], $result);

        // Verify config can be loaded back
        $loadedConfig = require $this->tempDir . '/cache/workerman/config.cache.php';
        $this->assertArrayHasKey('workerman', $loadedConfig);
        $this->assertArrayHasKey('process', $loadedConfig);
        $this->assertArrayHasKey('scheduler', $loadedConfig);
        $this->assertArrayHasKey('build', $loadedConfig);
        $this->assertSame(['server' => ['listen' => 'http://0.0.0.0:8080']], $loadedConfig['workerman']);
        $this->assertSame(['processes' => []], $loadedConfig['process']);
        $this->assertSame(['schedules' => []], $loadedConfig['scheduler']);
        $this->assertSame(['build_dir' => '/tmp/build'], $loadedConfig['build']);
    }

    public function testGetWorkermanConfigReturnsCorrectSection(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        $workermanConfig = ['server' => ['listen' => 'http://0.0.0.0:8080']];
        $loader->setWorkermanConfig($workermanConfig);
        $loader->setProcessConfig(['processes' => []]);
        $loader->setSchedulerConfig(['schedules' => []]);
        $loader->setBuildConfig(['build_dir' => '/tmp/build']);

        $this->assertSame($workermanConfig, $loader->getWorkermanConfig());
    }

    public function testGetProcessConfigReturnsCorrectSection(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        $processConfig = ['processes' => [['name' => 'test']]];
        $loader->setWorkermanConfig(['server' => []]);
        $loader->setProcessConfig($processConfig);
        $loader->setSchedulerConfig(['schedules' => []]);
        $loader->setBuildConfig(['build_dir' => '/tmp/build']);

        $this->assertSame($processConfig, $loader->getProcessConfig());
    }

    public function testGetSchedulerConfigReturnsCorrectSection(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        $schedulerConfig = ['schedules' => [['name' => 'test']]];
        $loader->setWorkermanConfig(['server' => []]);
        $loader->setProcessConfig(['processes' => []]);
        $loader->setSchedulerConfig($schedulerConfig);
        $loader->setBuildConfig(['build_dir' => '/tmp/build']);

        $this->assertSame($schedulerConfig, $loader->getSchedulerConfig());
    }

    public function testGetBuildConfigReturnsCorrectSection(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        $buildConfig = ['build_dir' => '/tmp/build'];
        $loader->setWorkermanConfig(['server' => []]);
        $loader->setProcessConfig(['processes' => []]);
        $loader->setSchedulerConfig(['schedules' => []]);
        $loader->setBuildConfig($buildConfig);

        $this->assertSame($buildConfig, $loader->getBuildConfig());
    }

    public function testLoadFromCacheReturnsConfigWhenCacheFileExists(): void
    {
        // Create loader A, set config, warm up to write cache
        $loaderA = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $config = [
            'server' => ['listen' => 'http://0.0.0.0:8080'],
        ];
        $loaderA->setWorkermanConfig($config);
        $loaderA->setProcessConfig([]);
        $loaderA->setSchedulerConfig([]);
        $loaderA->setBuildConfig([]);
        $loaderA->warmUp($this->tempDir . '/cache');

        // Create loader B (no config set via setters) — should load from cache
        $loaderB = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $this->assertSame($config, $loaderB->getWorkermanConfig());
    }

    public function testLoadFromCacheRejectsWorldWritableCacheFile(): void
    {
        // Create loader, set config, warm up to write cache
        $loaderA = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $loaderA->setWorkermanConfig(['server' => ['listen' => 'http://0.0.0.0:8080']]);
        $loaderA->setProcessConfig([]);
        $loaderA->setSchedulerConfig([]);
        $loaderA->setBuildConfig([]);
        $loaderA->warmUp($this->tempDir . '/cache');

        $cachePath = $this->tempDir . '/cache/workerman/config.cache.php';

        // Make the cache file world-writable
        chmod($cachePath, 0666);

        // Create loader B (no config set via setters) — should reject world-writable cache
        $loaderB = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('world-writable');

        $loaderB->getWorkermanConfig();
    }

    public function testLoadFromCacheWithPrivateCacheFileContinuesToWork(): void
    {
        // Create loader A, set config, warm up to write cache
        $loaderA = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $config = [
            'server' => ['listen' => 'http://0.0.0.0:8080'],
        ];
        $loaderA->setWorkermanConfig($config);
        $loaderA->setProcessConfig([]);
        $loaderA->setSchedulerConfig([]);
        $loaderA->setBuildConfig([]);
        $loaderA->warmUp($this->tempDir . '/cache');

        $cachePath = $this->tempDir . '/cache/workerman/config.cache.php';

        // Make the cache file owner-only readable/writable
        chmod($cachePath, 0600);

        // Create loader B (no config set via setters) — should load from cache
        $loaderB = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $this->assertSame($config, $loaderB->getWorkermanConfig());
    }

    public function testLoadFromCacheRefusesWorldWritableCacheDirectory(): void
    {
        // Create loader A, set config, warm up to write cache
        $loaderA = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $loaderA->setWorkermanConfig(['server' => ['listen' => 'http://0.0.0.0:8080']]);
        $loaderA->setProcessConfig([]);
        $loaderA->setSchedulerConfig([]);
        $loaderA->setBuildConfig([]);
        $loaderA->warmUp($this->tempDir . '/cache');

        $cachePath = $this->tempDir . '/cache/workerman/config.cache.php';
        $cacheDir = dirname($cachePath);

        // The file itself is safe (0644); only the containing directory is world-writable.
        chmod($cachePath, 0644);
        chmod($cacheDir, 0777);

        // Create loader B (no config set via setters) — should reject the world-writable directory
        $loaderB = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('#configuration cache directory ".*" is world-writable #');

        $loaderB->getWorkermanConfig();
    }

    public function testLoadFromCacheRefusesGroupWritableCacheDirectoryOfAnotherGroup(): void
    {
        $foreignGroup = $this->findForeignGroup();
        if ($foreignGroup === null) {
            $this->markTestSkipped('No supplementary group available to chgrp to');
        }

        // Create loader A, set config, warm up to write cache
        $loaderA = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $loaderA->setWorkermanConfig(['server' => ['listen' => 'http://0.0.0.0:8080']]);
        $loaderA->setProcessConfig([]);
        $loaderA->setSchedulerConfig([]);
        $loaderA->setBuildConfig([]);
        $loaderA->warmUp($this->tempDir . '/cache');

        $cachePath = $this->tempDir . '/cache/workerman/config.cache.php';
        $cacheDir = dirname($cachePath);

        // Group-writable, but not world-writable; the file itself stays safe.
        chmod($cachePath, 0644);
        chmod($cacheDir, 0770);

        if (!chgrp($cacheDir, $foreignGroup)) {
            $this->markTestSkipped('chgrp to a group other than the process group requires membership');
        }

        // Create loader B (no config set via setters) — should reject the foreign-group directory
        $loaderB = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('#configuration cache directory ".*" is writable by group #');

        $loaderB->getWorkermanConfig();
    }

    public function testLoadFromCacheRefusesCacheFileOwnedByAnotherUser(): void
    {
        // Create loader A, set config, warm up to write cache
        $loaderA = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $loaderA->setWorkermanConfig(['server' => ['listen' => 'http://0.0.0.0:8080']]);
        $loaderA->setProcessConfig([]);
        $loaderA->setSchedulerConfig([]);
        $loaderA->setBuildConfig([]);
        $loaderA->warmUp($this->tempDir . '/cache');

        $cachePath = $this->tempDir . '/cache/workerman/config.cache.php';

        chmod($cachePath, 0644);
        if (!@chown($cachePath, 65534)) {
            $this->markTestSkipped('chown to another user requires root privileges');
        }

        // Create loader B (no config set via setters) — should reject the foreign-owned file
        $loaderB = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('#configuration cache file ".*" is owned by uid #');

        $loaderB->getWorkermanConfig();
    }

    public function testLoadFromCacheWithSecureDirectoryAndFilePermissionsStillWorks(): void
    {
        // Create loader A, set config, warm up to write cache
        $loaderA = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $config = [
            'server' => ['listen' => 'http://0.0.0.0:8080'],
        ];
        $loaderA->setWorkermanConfig($config);
        $loaderA->setProcessConfig([]);
        $loaderA->setSchedulerConfig([]);
        $loaderA->setBuildConfig([]);
        $loaderA->warmUp($this->tempDir . '/cache');

        $cachePath = $this->tempDir . '/cache/workerman/config.cache.php';
        $cacheDir = dirname($cachePath);

        // 0750 directory, 0644 file, owned by the process user — must load.
        chmod($cacheDir, 0750);
        chmod($cachePath, 0644);

        $loaderB = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $this->assertSame($config, $loaderB->getWorkermanConfig());

        // 0700 directory also works.
        chmod($cacheDir, 0700);

        $loaderC = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $this->assertSame($config, $loaderC->getWorkermanConfig());
    }

    public function testValidateCacheFilePermissionsLogsWarningWhenMetadataIsUnreadable(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var array<int, array{level: string, message: string}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message];
            }
        };

        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true, $logger);

        $missingPath = $this->tempDir . '/cache/workerman/does-not-exist.php';
        (new \ReflectionMethod(ConfigLoader::class, 'validateCacheFilePermissions'))
            ->invoke($loader, $missingPath);

        $this->assertCount(1, $logger->records);
        $this->assertSame('warning', $logger->records[0]['level']);
        $this->assertStringContainsString($missingPath, $logger->records[0]['message']);
    }

    private function findForeignGroup(): ?int
    {
        $groups = posix_getgroups();
        if ($groups === false) {
            return null;
        }

        $egid = posix_getegid();
        foreach ($groups as $gid) {
            if ($gid !== $egid) {
                return $gid;
            }
        }

        return null;
    }

    public function testLoadFreshThrowsWhenNoConfigAndNoCache(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Configuration not available');

        $loader->getWorkermanConfig();
    }

    public function testLoadFreshThrowsForAnyGetterWhenNoConfigAndNoCache(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Configuration not available');

        $loader->getProcessConfig();
    }
}
