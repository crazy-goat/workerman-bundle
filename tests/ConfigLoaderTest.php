<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Tests;

use CrazyGoat\WorkermanBundle\ConfigLoader;
use PHPUnit\Framework\TestCase;

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

        // Set only process and scheduler config
        $loader->setProcessConfig(['some' => 'process']);
        $loader->setSchedulerConfig(['some' => 'scheduler']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('All config sections must be set before warming up. Missing: workerman');

        $loader->warmUp($this->tempDir . '/cache');
    }

    public function testWarmUpThrowsExceptionWhenProcessConfigIsMissing(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        // Set only workerman and scheduler config
        $loader->setWorkermanConfig(['some' => 'workerman']);
        $loader->setSchedulerConfig(['some' => 'scheduler']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('All config sections must be set before warming up. Missing: process');

        $loader->warmUp($this->tempDir . '/cache');
    }

    public function testWarmUpThrowsExceptionWhenSchedulerConfigIsMissing(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        // Set only workerman and process config
        $loader->setWorkermanConfig(['some' => 'workerman']);
        $loader->setProcessConfig(['some' => 'process']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('All config sections must be set before warming up. Missing: scheduler');

        $loader->warmUp($this->tempDir . '/cache');
    }

    public function testWarmUpThrowsExceptionWithMultipleMissingSections(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        // Set only workerman config
        $loader->setWorkermanConfig(['some' => 'workerman']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('All config sections must be set before warming up. Missing: process, scheduler');

        $loader->warmUp($this->tempDir . '/cache');
    }

    public function testWarmUpThrowsExceptionWhenNoConfigIsSet(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        // Don't set any config

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('All config sections must be set before warming up. Missing: workerman, process, scheduler');

        $loader->warmUp($this->tempDir . '/cache');
    }

    public function testWarmUpSucceedsWithAllConfigSectionsSet(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        // Set all config sections
        $loader->setWorkermanConfig(['server' => ['listen' => 'http://0.0.0.0:8080']]);
        $loader->setProcessConfig(['processes' => []]);
        $loader->setSchedulerConfig(['schedules' => []]);

        // Should not throw exception
        $result = $loader->warmUp($this->tempDir . '/cache');

        // Verify cache was created
        $this->assertFileExists($this->tempDir . '/cache/workerman/config.cache.php');
        $this->assertSame([], $result);

        // Verify config can be loaded back
        $loadedConfig = require $this->tempDir . '/cache/workerman/config.cache.php';
        $this->assertArrayHasKey(0, $loadedConfig);
        $this->assertArrayHasKey(1, $loadedConfig);
        $this->assertArrayHasKey(2, $loadedConfig);
        $this->assertSame(['server' => ['listen' => 'http://0.0.0.0:8080']], $loadedConfig[0]);
        $this->assertSame(['processes' => []], $loadedConfig[1]);
        $this->assertSame(['schedules' => []], $loadedConfig[2]);
    }

    public function testGetWorkermanConfigReturnsCorrectSection(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        $workermanConfig = ['server' => ['listen' => 'http://0.0.0.0:8080']];
        $loader->setWorkermanConfig($workermanConfig);
        $loader->setProcessConfig(['processes' => []]);
        $loader->setSchedulerConfig(['schedules' => []]);
        $loader->warmUp($this->tempDir . '/cache');

        $this->assertSame($workermanConfig, $loader->getWorkermanConfig());
    }

    public function testGetProcessConfigReturnsCorrectSection(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        $processConfig = ['processes' => [['name' => 'test']]];
        $loader->setWorkermanConfig(['server' => []]);
        $loader->setProcessConfig($processConfig);
        $loader->setSchedulerConfig(['schedules' => []]);
        $loader->warmUp($this->tempDir . '/cache');

        $this->assertSame($processConfig, $loader->getProcessConfig());
    }

    public function testGetSchedulerConfigReturnsCorrectSection(): void
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);

        $schedulerConfig = ['schedules' => [['name' => 'test']]];
        $loader->setWorkermanConfig(['server' => []]);
        $loader->setProcessConfig(['processes' => []]);
        $loader->setSchedulerConfig($schedulerConfig);
        $loader->warmUp($this->tempDir . '/cache');

        $this->assertSame($schedulerConfig, $loader->getSchedulerConfig());
    }
}
