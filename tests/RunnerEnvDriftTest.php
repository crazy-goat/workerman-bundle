<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\KernelFactory;
use CrazyGoat\WorkermanBundle\Runner;
use CrazyGoat\WorkermanBundle\Test\Fixtures\EnvDriftKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Boot-level test for the Runner env-drift cache re-warm branch (issue #1037).
 *
 * Warms the config cache with one env value through a real kernel boot
 * (Runner::warmUpCache() forks a child that boots the kernel), then walks
 * the start path exactly as Runner::run() does with a changed env value and
 * asserts the new value is picked up.
 */
final class RunnerEnvDriftTest extends TestCase
{
    private const ENV_VAR = 'WMB_RUNNER_ENV_DRIFT_PORT';
    private const FIRST_PORT = '18080';
    private const SECOND_PORT = '18081';

    private string $projectDir;
    /** @var list<string> */
    private array $touchedEnvVars = [];

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/wmb_runner_env_drift_' . uniqid();
        mkdir($this->projectDir . '/config/packages', 0777, true);
        mkdir($this->projectDir . '/var', 0777, true);

        file_put_contents(
            $this->projectDir . '/config/packages/workerman.yaml',
            <<<'YAML'
                workerman:
                    servers:
                        - name: 'env-drift'
                          listen: 'http://127.0.0.1:%env(int:WMB_RUNNER_ENV_DRIFT_PORT)%'
                          processes: 1
                YAML,
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->touchedEnvVars as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        }
        $this->touchedEnvVars = [];

        $this->removeDir($this->projectDir);
    }

    public function testStartPathRewarmsCacheWhenTrackedEnvChanges(): void
    {
        $kernelFactory = new KernelFactory(
            fn(): KernelInterface => new EnvDriftKernel('test', false, $this->projectDir),
            [],
        );
        $runner = new Runner($kernelFactory);

        // 1. Warm the cache with the first port through a real kernel boot.
        $this->setTestEnv(self::ENV_VAR, self::FIRST_PORT);
        $configLoader = $this->invokeRunnerMethod($runner, 'createConfigLoader');
        $this->invokeRunnerMethod($runner, 'warmUpCache', $configLoader);

        $config = $configLoader->getWorkermanConfig();
        $this->assertStringContainsString(
            ':' . self::FIRST_PORT,
            $config['servers'][0]['listen'],
            'Cache must hold the config resolved with the first env value.',
        );

        // 2. Change the env value and walk the start path as Runner::run()
        //    does: detect the drift, force a re-warm, reload the loader.
        $this->setTestEnv(self::ENV_VAR, self::SECOND_PORT);
        $configLoader = $this->invokeRunnerMethod($runner, 'createConfigLoader');
        $this->assertTrue(
            $configLoader->hasTrackedEnvChanged(),
            'The changed env value must be detected against the warmed snapshot.',
        );
        $this->invokeRunnerMethod($runner, 'warmUpCache', $configLoader, true);
        $configLoader = $this->invokeRunnerMethod($runner, 'createConfigLoader');

        // 3. The start path picks up the new value.
        $config = $configLoader->getWorkermanConfig();
        $this->assertStringContainsString(
            ':' . self::SECOND_PORT,
            $config['servers'][0]['listen'],
            'After the re-warm branch, the new env value must be picked up.',
        );
        $this->assertFalse(
            $configLoader->hasTrackedEnvChanged(),
            'After re-warming, the snapshot must match the environment again.',
        );
    }

    /**
     * Sets a test env var everywhere ConfigLoader::readEnvValues() looks
     * (`$_SERVER`, `$_ENV`, `getenv()`), restoring the previous state
     * afterwards.
     */
    private function setTestEnv(string $name, string $value): void
    {
        $_SERVER[$name] = $value;
        $_ENV[$name] = $value;
        putenv($name . '=' . $value);

        if (!\in_array($name, $this->touchedEnvVars, true)) {
            $this->touchedEnvVars[] = $name;
        }
    }

    private function invokeRunnerMethod(Runner $runner, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod(Runner::class, $method);

        return $reflection->invoke($runner, ...$args);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
