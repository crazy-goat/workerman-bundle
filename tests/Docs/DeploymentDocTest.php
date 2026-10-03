<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Docs;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

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

        foreach (['WORKERMAN_TRUST_UNSAFE_CONFIG_CACHE=1', 'config.cache.php', 'cache:warmup', 'SIGINT', 'SIGQUIT', 'opcache.enable_cli=1', 'cache:clear'] as $needle) {
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

    public function testReloadClearsTheOpcacheAsThePageSays(): void
    {
        $page = DocsHelper::read(self::PAGE);

        self::assertStringContainsString('`Utils::clearOpcache()`', $page);
        self::assertStringContainsString('Utils::clearOpcache(...)', file_get_contents(DocsHelper::rootDir() . '/src/Runner.php') ?: '');
    }

    public function testDockerfileWarmsUpAsTheRuntimeUserAndRunsInTheForeground(): void
    {
        self::assertSame(1, preg_match_all('/```dockerfile\n(FROM php:.*?)```/s', DocsHelper::read(self::PAGE), $blocks));
        $dockerfile = $blocks[1][0];

        // COPY --chown must create /app before WORKDIR: a WORKDIR first makes /app owned by root and the build fails.
        $copy = strpos($dockerfile, 'COPY --chown=www-data:www-data . /app');
        $workdir = strpos($dockerfile, 'WORKDIR /app');
        $user = strpos($dockerfile, 'USER www-data');
        $warmup = strpos($dockerfile, 'bin/console cache:warmup');

        self::assertNotFalse($copy);
        self::assertNotFalse($workdir);
        self::assertNotFalse($user);
        self::assertNotFalse($warmup);
        self::assertLessThan($workdir, $copy);
        self::assertLessThan($warmup, $user);
        self::assertStringContainsString('CMD ["bin/console", "workerman:server", "start"]', $dockerfile);
        self::assertStringNotContainsString('start", "-d"', $dockerfile);
        self::assertStringContainsString('opcache.enable_cli=1', $dockerfile);
    }

    public function testYamlExamplesParseAndGracePeriodsAreLongerThanStopTimeout(): void
    {
        self::assertSame(3, preg_match_all('/```yaml\n(.*?)```/s', DocsHelper::read(self::PAGE), $blocks));

        $config = Yaml::parse($blocks[1][0]);
        self::assertIsArray($config);
        self::assertArrayHasKey('stop_timeout', $config['workerman']);
        self::assertArrayHasKey('processes', $config['workerman']['servers'][0]);
        self::assertStringStartsWith('http://0.0.0.0:', $config['workerman']['servers'][0]['listen']);
        $stopTimeout = $config['workerman']['stop_timeout'];
        self::assertGreaterThan(DocsHelper::configLeaves()['stop_timeout']->getDefaultValue(), $stopTimeout);

        $compose = Yaml::parse($blocks[1][1]);
        self::assertIsArray($compose);
        self::assertSame('15s', $compose['services']['app']['stop_grace_period']);
        self::assertGreaterThan($stopTimeout, (int) $compose['services']['app']['stop_grace_period']);

        $deployment = Yaml::parse($blocks[1][2]);
        self::assertIsArray($deployment);
        self::assertSame('Deployment', $deployment['kind']);
        $grace = $deployment['spec']['template']['spec']['terminationGracePeriodSeconds'];
        self::assertGreaterThan($stopTimeout, $grace);
        self::assertStringContainsString(sprintf('(here %d and %d)', $grace, $stopTimeout), DocsHelper::read(self::PAGE));
    }

    public function testGrpcVariableAndLogRulesAreOnThePage(): void
    {
        $page = DocsHelper::read(self::PAGE);

        self::assertStringContainsString('ENV GRPC_ENABLE_FORK_SUPPORT=1', $page);
        self::assertStringContainsString('/dev/stderr', $page);
        self::assertStringContainsString('terminationGracePeriodSeconds', $page);
    }
}
