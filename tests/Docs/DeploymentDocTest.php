<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Docs;

use PHPUnit\Framework\TestCase;

/**
 * docs/deployment.md must match the config tree and the systemd unit example must be sane (issue #878, section 11).
 *
 * @coversNothing
 */
final class DeploymentDocTest extends TestCase
{
    private const PAGE = 'docs/deployment.md';

    public function testNamedConfigKeysExist(): void
    {
        $leaves = DocsHelper::configLeaves();
        $page = DocsHelper::read(self::PAGE);

        foreach (['user', 'group', 'stop_timeout', 'runtime_dir', 'pid_file', 'log_file', 'stdout_file'] as $key) {
            self::assertArrayHasKey($key, $leaves);
            self::assertStringContainsString('`' . $key . '`', $page);
        }
    }

    public function testStopTimeoutDefaultIsTheOneOnThePage(): void
    {
        $leaf = DocsHelper::configLeaves()['stop_timeout'];

        self::assertSame(2, $leaf->getDefaultValue());
        self::assertStringContainsString('`stop_timeout` seconds (default 2)', DocsHelper::read(self::PAGE));
    }

    public function testEnvVarAndSignalsAreNamed(): void
    {
        $page = DocsHelper::read(self::PAGE);

        foreach (['WORKERMAN_TRUST_UNSAFE_CONFIG_CACHE=1', 'config.cache.php', 'cache:warmup', 'SIGINT', 'SIGQUIT', 'opcache.enable_cli=1'] as $needle) {
            self::assertStringContainsString($needle, $page);
        }
    }

    public function testSystemdUnitStartsInTheForegroundAndWaitsLongEnough(): void
    {
        $page = DocsHelper::read(self::PAGE);

        self::assertSame(1, preg_match_all('/```ini\n(\[Unit\].*?)```/s', $page, $blocks));
        $unit = $blocks[1][0];

        self::assertSame(1, preg_match_all('/^ExecStart=.*workerman:server start$/m', $unit));
        self::assertStringNotContainsString(' -d', $unit);
        self::assertStringContainsString('KillSignal=SIGINT', $unit);
        self::assertStringContainsString('Restart=always', $unit);
        self::assertSame(1, preg_match_all('/^TimeoutStopSec=(\d+)$/m', $unit, $timeout));
        self::assertGreaterThan(DocsHelper::configLeaves()['stop_timeout']->getDefaultValue(), (int) $timeout[1][0]);
    }

    public function testReloadAndRestartCommandsExist(): void
    {
        $commands = DocsHelper::read('docs/commands.md');

        self::assertStringContainsString('| `reload` |', $commands);
        self::assertStringContainsString('| `restart` |', $commands);
    }
}
