<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Docs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every environment variable that the bundle reads must be in docs/configuration.md (issue #878, section 5).
 *
 * @coversNothing
 */
final class EnvVarDocTest extends TestCase
{
    private const PAGE = 'docs/configuration.md';

    /**
     * Upper-case names that are not settings of the bundle: server and CGI variables.
     */
    private const IGNORED = [
        'GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'TRACE', 'CONNECT',
    ];

    private const IGNORED_PREFIXES = ['HTTP_', 'REQUEST_', 'REMOTE_', 'SERVER_', 'CONTENT_', 'SCRIPT_', 'PATH_', 'QUERY_'];

    public static function tearDownAfterClass(): void
    {
        // Leave the garbage collector as it was: RebootStrategyTest counts collected cycles.
        gc_collect_cycles();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function envVarProvider(): iterable
    {
        foreach (self::findEnvVars() as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('envVarProvider')]
    public function testEnvVarIsDocumented(string $name): void
    {
        self::assertMatchesRegularExpression(
            '/^\| `' . preg_quote($name, '/') . '` \|/m',
            DocsHelper::read(self::PAGE),
            sprintf('The environment variable %s is read by the bundle, but %s has no table row for it.', $name, self::PAGE),
        );
    }

    public function testTheScanFindsTheKnownVariables(): void
    {
        $found = self::findEnvVars();

        foreach (['WORKERMAN_RUNTIME_DIR', 'WORKERMAN_CACHE_WARMUP_TIMEOUT', 'WORKERMAN_TRUST_UNSAFE_CONFIG_CACHE', 'GRPC_ENABLE_FORK_SUPPORT', 'APP_CACHE_DIR'] as $name) {
            self::assertContains($name, $found, sprintf('The scan must find %s; the patterns in this test are out of date.', $name));
        }
    }

    /**
     * @return list<string>
     */
    private static function findEnvVars(): array
    {
        $names = [];
        $files = array_merge(
            glob(DocsHelper::rootDir() . '/resources/*') ?: [],
            self::phpFiles(DocsHelper::rootDir() . '/src'),
        );

        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            preg_match_all('/(?:\$_(?:SERVER|ENV)\[|getenv\(\s*)\'([A-Z][A-Z0-9_]+)\'/', $code, $superglobals);
            preg_match_all('/const\s+ENV_VAR\s*=\s*\'([A-Z][A-Z0-9_]+)\'/', $code, $constants);

            foreach ([...$superglobals[1], ...$constants[1]] as $name) {
                if (!in_array($name, self::IGNORED, true) && !self::hasIgnoredPrefix($name)) {
                    $names[$name] = true;
                }
            }
        }

        $names = array_keys($names);
        sort($names);

        return $names;
    }

    private static function hasIgnoredPrefix(string $name): bool
    {
        foreach (self::IGNORED_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
