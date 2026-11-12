<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\ConfigCacheGuardConfig;
use CrazyGoat\WorkermanBundle\ConfigLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class ConfigLoaderTest extends TestCase
{
    private string $tempDir;

    private string|false $savedTrustEnv = false;

    /** @var list<string> */
    private array $testEnvNames = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/config-loader-test-' . uniqid();

        $previousUmask = umask(0077);
        try {
            mkdir($this->tempDir . '/config/packages', 0777, true);
            mkdir($this->tempDir . '/cache', 0777, true);
        } finally {
            umask($previousUmask);
        }

        unset($_SERVER[ConfigCacheGuardConfig::ENV_VAR], $_ENV[ConfigCacheGuardConfig::ENV_VAR]);
        $this->savedTrustEnv = function_exists('getenv') ? getenv(ConfigCacheGuardConfig::ENV_VAR) : false;
        if (function_exists('putenv')) {
            putenv(ConfigCacheGuardConfig::ENV_VAR);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->testEnvNames as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
            if (function_exists('putenv')) {
                putenv($name);
            }
        }
        $this->testEnvNames = [];
        ConfigCacheGuardConfig::reset();
        unset($_SERVER[ConfigCacheGuardConfig::ENV_VAR], $_ENV[ConfigCacheGuardConfig::ENV_VAR]);
        if (function_exists('putenv')) {
            if ($this->savedTrustEnv === false) {
                putenv(ConfigCacheGuardConfig::ENV_VAR);
            } else {
                putenv(ConfigCacheGuardConfig::ENV_VAR . '=' . $this->savedTrustEnv);
            }
        }
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

        $loader->setWorkermanConfig(['some' => 'workerman']);
        $loader->setBuildConfig(['some' => 'build']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('All config sections must be set before warming up. Missing: process, scheduler');

        $loader->warmUp($this->tempDir . '/cache');
    }

    public function testWarmUpThrowsExceptionWhenNoConfigIsSet(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('All config sections must be set before warming up. Missing: workerman, process, scheduler, build');

        $loader->warmUp($this->tempDir . '/cache');
    }

    public function testWarmUpSucceedsWithAllConfigSectionsSet(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        $loader->setWorkermanConfig(['server' => ['listen' => 'http://0.0.0.0:8080']]);
        $loader->setProcessConfig(['processes' => []]);
        $loader->setSchedulerConfig(['schedules' => []]);
        $loader->setBuildConfig(['build_dir' => '/tmp/build']);

        $result = $loader->warmUp($this->tempDir . '/cache');

        $this->assertFileExists($this->tempDir . '/cache/workerman/config.cache.php');
        $this->assertSame([], $result);

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
        $loaderA = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $config = [
            'server' => ['listen' => 'http://0.0.0.0:8080'],
        ];
        $loaderA->setWorkermanConfig($config);
        $loaderA->setProcessConfig([]);
        $loaderA->setSchedulerConfig([]);
        $loaderA->setBuildConfig([]);
        $loaderA->warmUp($this->tempDir . '/cache');

        $loaderB = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $this->assertSame($config, $loaderB->getWorkermanConfig());
    }

    public function testLoadFromCacheRejectsWorldWritableCacheFile(): void
    {
        $loaderA = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $loaderA->setWorkermanConfig(['server' => ['listen' => 'http://0.0.0.0:8080']]);
        $loaderA->setProcessConfig([]);
        $loaderA->setSchedulerConfig([]);
        $loaderA->setBuildConfig([]);
        $loaderA->warmUp($this->tempDir . '/cache');

        $cachePath = $this->tempDir . '/cache/workerman/config.cache.php';

        chmod($cachePath, 0666);

        $loaderB = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('world-writable');

        $loaderB->getWorkermanConfig();
    }

    public function testLoadFromCacheWithPrivateCacheFileContinuesToWork(): void
    {
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

        chmod($cachePath, 0600);

        $loaderB = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $this->assertSame($config, $loaderB->getWorkermanConfig());
    }

    public function testLoadFromCacheRefusesWorldWritableCacheDirectory(): void
    {
        $loaderA = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $loaderA->setWorkermanConfig(['server' => ['listen' => 'http://0.0.0.0:8080']]);
        $loaderA->setProcessConfig([]);
        $loaderA->setSchedulerConfig([]);
        $loaderA->setBuildConfig([]);
        $loaderA->warmUp($this->tempDir . '/cache');

        $cachePath = $this->tempDir . '/cache/workerman/config.cache.php';
        $cacheDir = dirname($cachePath);

        chmod($cachePath, 0644);
        chmod($cacheDir, 0777);

        $loaderB = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('#configuration cache directory ".*" is world-writable #');

        $loaderB->getWorkermanConfig();
    }

    public function testLoadFromCacheAcceptsGroupWritableCacheDirectoryOfSupplementaryGroup(): void
    {
        $supplementaryGroup = $this->findSupplementaryGroup();
        if ($supplementaryGroup === null) {
            $this->markTestSkipped('No supplementary group available to chgrp to');
        }

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

        chmod($cachePath, 0644);
        chmod($cacheDir, 0770);

        if (!chgrp($cacheDir, $supplementaryGroup)) {
            $this->markTestSkipped('chgrp to a supplementary group requires membership');
        }

        $loaderB = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $this->assertSame($config, $loaderB->getWorkermanConfig());
    }

    public function testLoadFromCacheAcceptsGroupWritableCacheDirectoryOfOwnEffectiveGroup(): void
    {
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

        chmod($cachePath, 0644);
        chmod($cacheDir, 0770);

        if (!@chgrp($cacheDir, posix_getegid())) {
            $this->markTestSkipped('chgrp to the effective group failed');
        }

        $loaderB = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $this->assertSame($config, $loaderB->getWorkermanConfig());
    }

    public function testLoadFromCacheRefusesGroupWritableCacheDirectoryOfForeignGroup(): void
    {
        $foreignGroup = $this->findForeignGroup();
        if ($foreignGroup === null) {
            $this->markTestSkipped('No candidate foreign group found');
        }

        $loaderA = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $loaderA->setWorkermanConfig(['server' => ['listen' => 'http://0.0.0.0:8080']]);
        $loaderA->setProcessConfig([]);
        $loaderA->setSchedulerConfig([]);
        $loaderA->setBuildConfig([]);
        $loaderA->warmUp($this->tempDir . '/cache');

        $cachePath = $this->tempDir . '/cache/workerman/config.cache.php';
        $cacheDir = dirname($cachePath);

        chmod($cachePath, 0644);
        chmod($cacheDir, 0770);

        if (!@chgrp($cacheDir, $foreignGroup)) {
            $this->markTestSkipped('chgrp to a foreign group requires root privileges');
        }

        $loaderB = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('#configuration cache directory ".*" is writable by group #');

        $loaderB->getWorkermanConfig();
    }

    public function testLoadFromCacheRefusesCacheFileOwnedByAnotherUser(): void
    {
        $loaderA = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $loaderA->setWorkermanConfig(['server' => ['listen' => 'http://0.0.0.0:8080']]);
        $loaderA->setProcessConfig([]);
        $loaderA->setSchedulerConfig([]);
        $loaderA->setBuildConfig([]);
        $loaderA->warmUp($this->tempDir . '/cache');

        $cachePath = $this->tempDir . '/cache/workerman/config.cache.php';

        chmod(dirname($cachePath), 0700);
        chmod($cachePath, 0644);
        if (!@chown($cachePath, 65534)) {
            $this->markTestSkipped('chown to another user requires root privileges');
        }

        $loaderB = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('#configuration cache file ".*" is owned by uid #');

        $loaderB->getWorkermanConfig();
    }

    public function testLoadFromCacheWithSecureDirectoryAndFilePermissionsStillWorks(): void
    {
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

        chmod($cacheDir, 0750);
        chmod($cachePath, 0644);

        $loaderB = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $this->assertSame($config, $loaderB->getWorkermanConfig());

        chmod($cacheDir, 0700);

        $loaderC = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $this->assertSame($config, $loaderC->getWorkermanConfig());
    }

    public function testWarmUpKeepsCacheArtifactsNonWorldWritableUnderPermissiveUmask(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $loader->setWorkermanConfig(['server' => ['listen' => 'http://0.0.0.0:8080']]);
        $loader->setProcessConfig([]);
        $loader->setSchedulerConfig([]);
        $loader->setBuildConfig([]);

        $previousUmask = umask(0000);
        try {
            $loader->warmUp($this->tempDir . '/cache');
        } finally {
            umask($previousUmask);
        }

        $cachePath = $this->tempDir . '/cache/workerman/config.cache.php';
        $cacheDir = dirname($cachePath);

        self::assertDirectoryExists($cacheDir);
        self::assertFileExists($cachePath);

        $dirPerms = fileperms($cacheDir);
        $filePerms = fileperms($cachePath);
        self::assertIsInt($dirPerms);
        self::assertIsInt($filePerms);

        self::assertSame(0, $dirPerms & 0o002, 'warmUp() must leave the cache directory non-world-writable even under umask 0000');
        self::assertSame(0, $filePerms & 0o002, 'warmUp() must leave the cache file non-world-writable even under umask 0000');
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

    public function testValidateCacheFilePermissionsDoesNotThrowWithThrowingErrorHandlerAndNoLogger(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        $missingPath = $this->tempDir . '/cache/workerman/does-not-exist.php';
        $logFile = $this->tempDir . '/error-throwing.log';
        $userWarningInvocations = 0;

        set_error_handler(
            static function (int $severity, string $message) use (&$userWarningInvocations): bool {
                if ($severity === \E_USER_WARNING) {
                    ++$userWarningInvocations;

                    throw new \ErrorException($message, 0, $severity);
                }

                return true;
            },
        );

        ini_set('error_log', $logFile);
        try {
            (new \ReflectionMethod(ConfigLoader::class, 'validateCacheFilePermissions'))
                ->invoke($loader, $missingPath);
        } finally {
            restore_error_handler();
            ini_restore('error_log');
        }

        $this->assertSame(0, $userWarningInvocations, 'the fail-open warning must not reach the error handler');
        $this->assertFileExists($logFile);
        $logContent = file_get_contents($logFile);

        $this->assertIsString($logContent, 'Failed to read error_log capture file');
        $this->assertStringContainsString($missingPath, $logContent);
    }

    public function testLoadFromCacheFallsThroughToLoadFreshWhenDirectoryIsUnreadable(): void
    {
        $loaderA = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $loaderA->setWorkermanConfig(['server' => ['listen' => 'http://0.0.0.0:8080']]);
        $loaderA->setProcessConfig([]);
        $loaderA->setSchedulerConfig([]);
        $loaderA->setBuildConfig([]);
        $loaderA->warmUp($this->tempDir . '/cache');

        $cachePath = $this->tempDir . '/cache/workerman/config.cache.php';
        $cacheDir = dirname($cachePath);

        $this->assertFileExists($cachePath);

        if (!@chmod($cacheDir, 0000)) {
            $this->markTestSkipped('chmod 0000 on the cache directory failed');
        }

        try {
            if (is_file($cachePath)) {
                $this->markTestSkipped('chmod 0000 on the cache directory did not make is_file() return false on this host');
            }

            $loaderB = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('Configuration not available');

            $loaderB->getWorkermanConfig();
        } finally {
            chmod($cacheDir, 0700);
        }
    }

    public function testCheckCacheFilePermissionsAcceptsSecurePermissions(): void
    {
        $verdict = ConfigLoader::checkCacheFilePermissions(
            '/tmp/cache/workerman/config.cache.php',
            0750,
            33,
            0644,
            1000,
            1000,
            [33, 100],
        );

        $this->assertNull($verdict['error']);
        $this->assertNull($verdict['warn']);
    }

    public function testCheckCacheFilePermissionsRefusesWorldWritableDirectory(): void
    {
        $verdict = ConfigLoader::checkCacheFilePermissions(
            '/tmp/cache/workerman/config.cache.php',
            0777,
            33,
            0644,
            1000,
            1000,
            [33],
        );

        $this->assertNull($verdict['warn']);
        $this->assertNotNull($verdict['error']);
        $this->assertStringContainsString('world-writable', $verdict['error']);
        $this->assertStringContainsString('/tmp/cache/workerman', $verdict['error']);
    }

    public function testCheckCacheFilePermissionsRefusesGroupWritableDirectoryOfForeignGroup(): void
    {
        $verdict = ConfigLoader::checkCacheFilePermissions(
            '/tmp/cache/workerman/config.cache.php',
            0770,
            200,
            0644,
            1000,
            1000,
            [33],
        );

        $this->assertNull($verdict['warn']);
        $this->assertNotNull($verdict['error']);
        $this->assertStringContainsString('is writable by group 200', $verdict['error']);
    }

    public function testCheckCacheFilePermissionsAcceptsGroupWritableDirectoryOfSupplementaryGroup(): void
    {
        $verdict = ConfigLoader::checkCacheFilePermissions(
            '/tmp/cache/workerman/config.cache.php',
            0770,
            100,
            0644,
            1000,
            1000,
            [33, 100],
        );

        $this->assertNull($verdict['error']);
        $this->assertNull($verdict['warn']);
    }

    public function testCheckCacheFilePermissionsRefusesFileOwnedByAnotherUser(): void
    {
        $verdict = ConfigLoader::checkCacheFilePermissions(
            '/tmp/cache/workerman/config.cache.php',
            0700,
            33,
            0644,
            65534,
            1000,
            [33],
        );

        $this->assertNull($verdict['warn']);
        $this->assertNotNull($verdict['error']);
        $this->assertStringContainsString('is owned by uid 65534', $verdict['error']);
        $this->assertStringNotContainsString(ConfigCacheGuardConfig::ENV_VAR, $verdict['error']);
    }

    public function testCheckCacheFilePermissionsRefusesWorldWritableFile(): void
    {
        $verdict = ConfigLoader::checkCacheFilePermissions(
            '/tmp/cache/workerman/config.cache.php',
            0700,
            33,
            0666,
            1000,
            1000,
            [33],
        );

        $this->assertNull($verdict['warn']);
        $this->assertNotNull($verdict['error']);
        $this->assertStringContainsString('world-writable', $verdict['error']);
    }

    public function testCheckCacheFilePermissionsWarnsWhenMetadataIsUnreadable(): void
    {
        $verdict = ConfigLoader::checkCacheFilePermissions(
            '/tmp/cache/workerman/config.cache.php',
            false,
            false,
            false,
            false,
            1000,
            [33],
        );

        $this->assertNull($verdict['error']);
        $this->assertNotNull($verdict['warn']);
        $this->assertStringContainsString('/tmp/cache/workerman/config.cache.php', $verdict['warn']);
    }

    public function testCheckCacheFilePermissionsWithTrustDowngradesWorldWritableDirectoryToWarning(): void
    {
        $verdict = ConfigLoader::checkCacheFilePermissions(
            '/tmp/cache/workerman/config.cache.php',
            0777,
            33,
            0644,
            1000,
            1000,
            [33],
            true,
        );

        $this->assertNull($verdict['error']);
        $this->assertNotNull($verdict['warn']);
        $this->assertStringContainsString('world-writable', $verdict['warn']);
        $this->assertStringContainsString(ConfigCacheGuardConfig::ENV_VAR, $verdict['warn']);
    }

    public function testCheckCacheFilePermissionsWithTrustDowngradesForeignGroupWritableDirectoryToWarning(): void
    {
        $verdict = ConfigLoader::checkCacheFilePermissions(
            '/tmp/cache/workerman/config.cache.php',
            0770,
            200,
            0644,
            1000,
            1000,
            [33],
            true,
        );

        $this->assertNull($verdict['error']);
        $this->assertNotNull($verdict['warn']);
        $this->assertStringContainsString('is writable by group 200', $verdict['warn']);
    }

    public function testCheckCacheFilePermissionsWithTrustDowngradesForeignOwnedFileToWarning(): void
    {
        $verdict = ConfigLoader::checkCacheFilePermissions(
            '/tmp/cache/workerman/config.cache.php',
            0700,
            33,
            0644,
            65534,
            1000,
            [33],
            true,
        );

        $this->assertNull($verdict['error']);
        $this->assertNotNull($verdict['warn']);
        $this->assertStringContainsString('is owned by uid 65534', $verdict['warn']);
    }

    public function testCheckCacheFilePermissionsWithTrustDowngradesWorldWritableFileToWarning(): void
    {
        $verdict = ConfigLoader::checkCacheFilePermissions(
            '/tmp/cache/workerman/config.cache.php',
            0700,
            33,
            0666,
            1000,
            1000,
            [33],
            true,
        );

        $this->assertNull($verdict['error']);
        $this->assertNotNull($verdict['warn']);
        $this->assertStringContainsString('world-writable', $verdict['warn']);
    }

    public function testCheckCacheFilePermissionsWithTrustAcceptsSecurePermissions(): void
    {
        $verdict = ConfigLoader::checkCacheFilePermissions(
            '/tmp/cache/workerman/config.cache.php',
            0700,
            33,
            0644,
            1000,
            1000,
            [33],
            true,
        );

        $this->assertNull($verdict['error']);
        $this->assertNull($verdict['warn']);
    }

    public function testLoadFromCacheProceedsWithWarningForWorldWritableDirectoryWhenTrustSet(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var array<int, array{level: string, message: string}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message];
            }
        };

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

        chmod($cachePath, 0644);
        chmod($cacheDir, 0777);

        $_SERVER[ConfigCacheGuardConfig::ENV_VAR] = '1';
        try {
            $loaderB = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true, $logger);
            $this->assertSame($config, $loaderB->getWorkermanConfig());

            $this->assertCount(1, $logger->records);
            $this->assertSame('warning', $logger->records[0]['level']);
            $this->assertStringContainsString('world-writable', $logger->records[0]['message']);
            $this->assertStringContainsString(ConfigCacheGuardConfig::ENV_VAR, $logger->records[0]['message']);
        } finally {
            unset($_SERVER[ConfigCacheGuardConfig::ENV_VAR]);
            ConfigCacheGuardConfig::reset();
        }
    }

    public function testLoadFromCacheProceedsWithWarningForForeignOwnedFileWhenTrustSet(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var array<int, array{level: string, message: string}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message];
            }
        };

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

        chmod(dirname($cachePath), 0700);
        chmod($cachePath, 0644);
        if (!@chown($cachePath, 65534)) {
            $this->markTestSkipped('chown to another user requires root privileges');
        }

        ConfigCacheGuardConfig::set(true);
        try {
            $loaderB = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true, $logger);
            $this->assertSame($config, $loaderB->getWorkermanConfig());

            $this->assertCount(1, $logger->records);
            $this->assertSame('warning', $logger->records[0]['level']);
            $this->assertStringContainsString('is owned by uid', $logger->records[0]['message']);
        } finally {
            ConfigCacheGuardConfig::reset();
        }
    }

    public function testLoadFromCacheTriggersWarningViaErrorLogWhenTrustSetAndNoLogger(): void
    {
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
        chmod($cachePath, 0644);
        chmod(dirname($cachePath), 0777);

        $logFile = $this->tempDir . '/error.log';
        ini_set('error_log', $logFile);

        ConfigCacheGuardConfig::set(true);
        try {
            $loaderB = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
            $this->assertSame($config, $loaderB->getWorkermanConfig());
        } finally {
            ini_restore('error_log');
            ConfigCacheGuardConfig::reset();
        }

        $this->assertFileExists($logFile);
        $logContent = file_get_contents($logFile);

        $this->assertIsString($logContent, 'Failed to read error_log capture file');
        $this->assertStringContainsString('world-writable', $logContent);
        $this->assertStringContainsString(ConfigCacheGuardConfig::ENV_VAR, $logContent);
    }

    private function findSupplementaryGroup(): ?int
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

    private function findForeignGroup(): ?int
    {
        $groups = posix_getgroups();
        if ($groups === false) {
            return null;
        }

        $processGroups = array_merge([posix_getegid()], $groups);
        foreach ([65534, 999, 1, 65533, 2] as $candidate) {
            if (!in_array($candidate, $processGroups, true)) {
                return $candidate;
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

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shortEnvNameProvider(): iterable
    {
        yield 'plain' => ['PORT', 'PORT'];
        yield 'int processor' => ['int:WORKERS', 'WORKERS'];
        yield 'bool processor' => ['bool:DEBUG_FEATURE', 'DEBUG_FEATURE'];
        yield 'default with fallback' => ['default:FOO:bar', 'FOO'];
        yield 'stacked processors' => ['int:default:FOO:bar', 'FOO'];
        yield 'empty segment skipped' => ['default::BAR', 'BAR'];
    }

    #[DataProvider('shortEnvNameProvider')]
    public function testShortEnvNameDropsProcessorPrefix(string $ref, string $expected): void
    {
        $this->assertSame($expected, ConfigLoader::shortEnvName($ref));
    }

    public function testStackedProcessorsTrackEveryRemainingSegment(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $loader->setWorkermanConfig(['server' => ['listen' => 'x']]);
        $loader->setProcessConfig([]);
        $loader->setSchedulerConfig([]);
        $loader->setBuildConfig([]);
        $loader->setWorkermanEnvVarNames(['int:default:WMB_TEST_FOO:bar']);
        $loader->warmUp($this->tempDir . '/cache');

        /** @var array{config: array<string, mixed[]>, env: array<string, string|null>} $payload */
        $payload = require $this->tempDir . '/cache/workerman/config.cache.php';

        $this->assertSame(['WMB_TEST_FOO' => null, 'bar' => null], $payload['env']);
    }

    public function testWarmUpStoresEnvSnapshotWhenEnvVarNamesAreSet(): void
    {
        $this->setTestEnv('WMB_TEST_PORT', 'A');

        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $loader->setWorkermanConfig(['server' => ['listen' => 'http://0.0.0.0:A']]);
        $loader->setProcessConfig([]);
        $loader->setSchedulerConfig([]);
        $loader->setBuildConfig([]);
        $loader->setWorkermanEnvVarNames(['WMB_TEST_PORT', 'int:WMB_TEST_WORKERS']);
        $loader->warmUp($this->tempDir . '/cache');

        /** @var array{config: array<string, mixed[]>, env: array<string, string|null>} $payload */
        $payload = require $this->tempDir . '/cache/workerman/config.cache.php';

        $this->assertArrayHasKey('config', $payload);
        $this->assertArrayHasKey('env', $payload);
        $this->assertSame(['server' => ['listen' => 'http://0.0.0.0:A']], $payload['config']['workerman']);
        $this->assertSame(['WMB_TEST_PORT' => 'A', 'WMB_TEST_WORKERS' => null], $payload['env']);
    }

    public function testEnvelopeCacheLoadsConfigSections(): void
    {
        $this->setTestEnv('WMB_TEST_PORT', 'A');

        $loaderA = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $loaderA->setWorkermanConfig(['server' => ['listen' => 'http://0.0.0.0:A']]);
        $loaderA->setProcessConfig([]);
        $loaderA->setSchedulerConfig([]);
        $loaderA->setBuildConfig([]);
        $loaderA->setWorkermanEnvVarNames(['WMB_TEST_PORT']);
        $loaderA->warmUp($this->tempDir . '/cache');

        $loaderB = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $this->assertSame(['server' => ['listen' => 'http://0.0.0.0:A']], $loaderB->getWorkermanConfig());
        $this->assertFalse($loaderB->hasTrackedEnvChanged());
    }

    public function testHasTrackedEnvChangedDetectsChangedValue(): void
    {
        $this->setTestEnv('WMB_TEST_PORT', 'A');

        $loaderA = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $loaderA->setWorkermanConfig(['server' => ['listen' => 'http://0.0.0.0:A']]);
        $loaderA->setProcessConfig([]);
        $loaderA->setSchedulerConfig([]);
        $loaderA->setBuildConfig([]);
        $loaderA->setWorkermanEnvVarNames(['WMB_TEST_PORT']);
        $loaderA->warmUp($this->tempDir . '/cache');

        $this->setTestEnv('WMB_TEST_PORT', 'B');

        $loaderB = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $this->assertTrue($loaderB->hasTrackedEnvChanged());
    }

    public function testHasTrackedEnvChangedReturnsFalseForLegacyCache(): void
    {
        $loaderA = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $loaderA->setWorkermanConfig(['server' => ['listen' => 'http://0.0.0.0:8080']]);
        $loaderA->setProcessConfig([]);
        $loaderA->setSchedulerConfig([]);
        $loaderA->setBuildConfig([]);
        $loaderA->warmUp($this->tempDir . '/cache');

        $this->setTestEnv('WMB_TEST_PORT', 'B');

        $loaderB = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $this->assertFalse($loaderB->hasTrackedEnvChanged());
    }

    public function testHasTrackedEnvChangedReturnsFalseWhenCacheFileIsMissing(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        $this->assertFalse($loader->hasTrackedEnvChanged());
    }

    private function setTestEnv(string $name, string $value): void
    {
        $_SERVER[$name] = $value;
        $_ENV[$name] = $value;
        putenv($name . '=' . $value);

        $this->trackTestEnvForCleanup($name);
    }

    private function trackTestEnvForCleanup(string $name): void
    {
        if (!\in_array($name, $this->testEnvNames, true)) {
            $this->testEnvNames[] = $name;
        }
    }
}
