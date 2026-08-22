<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\ConfigCacheGuardConfig;
use CrazyGoat\WorkermanBundle\ConfigLoader;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class ConfigLoaderTest extends TestCase
{
    private string $tempDir;

    /** @var string|false the process env value captured at setUp, restored at tearDown */
    private string|false $savedTrustEnv = false;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/config-loader-test-' . uniqid();
        mkdir($this->tempDir . '/config/packages', 0777, true);
        mkdir($this->tempDir . '/cache', 0777, true);

        // Hermeticity: an exported WORKERMAN_TRUST_UNSAFE_CONFIG_CACHE must
        // not leak into strict-mode tests from the developer's shell — it can
        // arrive via the process env (getenv) or via the superglobals, which
        // PHP populates from the environment at startup.
        unset($_SERVER[ConfigCacheGuardConfig::ENV_VAR], $_ENV[ConfigCacheGuardConfig::ENV_VAR]);
        $this->savedTrustEnv = function_exists('getenv') ? getenv(ConfigCacheGuardConfig::ENV_VAR) : false;
        if (function_exists('putenv')) {
            putenv(ConfigCacheGuardConfig::ENV_VAR);
        }
    }

    protected function tearDown(): void
    {
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

    public function testLoadFromCacheAcceptsGroupWritableCacheDirectoryOfSupplementaryGroup(): void
    {
        $supplementaryGroup = $this->findSupplementaryGroup();
        if ($supplementaryGroup === null) {
            $this->markTestSkipped('No supplementary group available to chgrp to');
        }

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

        // Group-writable by a supplementary group of the process: the process
        // legitimately belongs to that group, so loading must succeed.
        chmod($cachePath, 0644);
        chmod($cacheDir, 0770);

        if (!chgrp($cacheDir, $supplementaryGroup)) {
            $this->markTestSkipped('chgrp to a supplementary group requires membership');
        }

        // Create loader B (no config set via setters) — should load from cache
        $loaderB = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $this->assertSame($config, $loaderB->getWorkermanConfig());
    }

    public function testLoadFromCacheAcceptsGroupWritableCacheDirectoryOfOwnEffectiveGroup(): void
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

        // Group-writable by the process's own effective group: accepted.
        chmod($cachePath, 0644);
        chmod($cacheDir, 0770);

        if (!@chgrp($cacheDir, posix_getegid())) {
            $this->markTestSkipped('chgrp to the effective group failed');
        }

        // Create loader B (no config set via setters) — should load from cache
        $loaderB = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $this->assertSame($config, $loaderB->getWorkermanConfig());
    }

    public function testLoadFromCacheRefusesGroupWritableCacheDirectoryOfForeignGroup(): void
    {
        $foreignGroup = $this->findForeignGroup();
        if ($foreignGroup === null) {
            $this->markTestSkipped('No candidate foreign group found');
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

        // Group-writable by a group the process does not belong to; the file
        // itself stays safe. chgrp to a foreign group needs root.
        chmod($cachePath, 0644);
        chmod($cacheDir, 0770);

        if (!@chgrp($cacheDir, $foreignGroup)) {
            $this->markTestSkipped('chgrp to a foreign group requires root privileges');
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

        // The containing directory must not contribute a second warning:
        // warmUp() pins umask(0077) internally, but pinning the mode here
        // keeps the test independent of how the directory is created and of
        // future changes, so exactly one refusal signal fires.
        chmod(dirname($cachePath), 0700);
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

    public function testValidateCacheFilePermissionsLogsWarningWhenMetadataUnreadableAndNoLogger(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        $missingPath = $this->tempDir . '/cache/workerman/does-not-exist.php';
        $logFile = $this->tempDir . '/error-nologger.log';

        // No logger: the warning must still be surfaced via error_log().
        ini_set('error_log', $logFile);
        try {
            (new \ReflectionMethod(ConfigLoader::class, 'validateCacheFilePermissions'))
                ->invoke($loader, $missingPath);
        } finally {
            ini_restore('error_log');
        }

        $this->assertFileExists($logFile);
        $logContent = file_get_contents($logFile);

        $this->assertIsString($logContent, 'Failed to read error_log capture file');
        $this->assertStringContainsString($missingPath, $logContent);
    }

    public function testValidateCacheFilePermissionsDoesNotThrowWithThrowingErrorHandlerAndNoLogger(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        $missingPath = $this->tempDir . '/cache/workerman/does-not-exist.php';
        $logFile = $this->tempDir . '/error-throwing.log';
        $userWarningInvocations = 0;

        // A throwing error handler (Symfony's DebugErrorHandler escalates
        // E_USER_WARNING to ErrorException in debug mode). The fail-open
        // warning must not invoke it — only error_log() may fire — so no
        // exception may escape, the handler must never see an E_USER_WARNING,
        // and the warning must still reach the log. The handler mirrors
        // Symfony's: it respects @-suppressed errors (the four
        // @fileperms()/@filegroup()/@fileowner() metadata reads must not
        // throw) and throws only on the E_USER_WARNING that trigger_error()
        // would previously have emitted.
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

        // The fail-open warning must reach the log via error_log() — without
        // ever touching the error handler, or fail-open would fail closed.
        $this->assertSame(0, $userWarningInvocations, 'the fail-open warning must not reach the error handler');
        $this->assertFileExists($logFile);
        $logContent = file_get_contents($logFile);

        $this->assertIsString($logContent, 'Failed to read error_log capture file');
        $this->assertStringContainsString($missingPath, $logContent);
    }

    public function testLoadFromCacheFallsThroughToLoadFreshWhenDirectoryIsUnreadable(): void
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

        // Precondition: the cache file must actually exist, otherwise this
        // test degenerates into testLoadFreshThrowsWhenNoConfigAndNoCache and
        // proves nothing.
        $this->assertFileExists($cachePath);

        // On POSIX, chmod 0000 on the containing directory makes stat() on a
        // path inside it fail with EACCES, so is_file() answers false and the
        // loadFromCache() gate falls through before permission validation runs.
        if (!@chmod($cacheDir, 0000)) {
            $this->markTestSkipped('chmod 0000 on the cache directory failed');
        }

        try {
            if (is_file($cachePath)) {
                $this->markTestSkipped('chmod 0000 on the cache directory did not make is_file() return false on this host');
            }

            // Create loader B (no config set via setters) — the is_file() gate
            // fails, so loading falls through to loadFresh() and the caller
            // gets a LogicException, not the fail-open warning.
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
        // Byte-identity pin: the strict path must not leak the opt-out marker.
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
        // The opt-out must not invent warnings for an already-safe cache.
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

        // Create loader A, set config, warm up to write cache.
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

        // The file itself is safe (0644); only the containing directory is world-writable.
        chmod($cachePath, 0644);
        chmod($cacheDir, 0777);

        // Exercise the real opt-out path: the env var, not the test holder.
        $_SERVER[ConfigCacheGuardConfig::ENV_VAR] = '1';
        try {
            // Create loader B (no config set via setters) — with the documented
            // opt-out the world-writable directory no longer refuses loading;
            // the advisory warning is emitted instead.
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

        // Create loader A, set config, warm up to write cache.
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

        // The containing directory must not contribute a second warning:
        // warmUp() pins umask(0077) internally, but pinning the mode here
        // keeps the test independent of how the directory is created and of
        // future changes, so assertCount(1) stays deterministic.
        chmod(dirname($cachePath), 0700);
        chmod($cachePath, 0644);
        if (!@chown($cachePath, 65534)) {
            $this->markTestSkipped('chown to another user requires root privileges');
        }

        ConfigCacheGuardConfig::set(true);
        try {
            // Create loader B (no config set via setters) — with the
            // documented opt-out the foreign-owned file no longer refuses
            // loading; the advisory warning is emitted instead.
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
        // Create loader A, set config, warm up to write cache.
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

        // No logger: the Runner path (launcher process) has none, so the
        // downgraded refusal must still surface via error_log().
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
}
