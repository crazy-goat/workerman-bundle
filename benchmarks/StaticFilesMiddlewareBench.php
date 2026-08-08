<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Benchmark;

use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Middleware\StaticFilesMiddleware;
use PhpBench\Benchmark\Metadata\Annotations\AfterMethods;
use PhpBench\Benchmark\Metadata\Annotations\BeforeMethods;
use PhpBench\Benchmark\Metadata\Annotations\Iterations;
use PhpBench\Benchmark\Metadata\Annotations\Revs;
use PhpBench\Benchmark\Metadata\Annotations\Warmup;
use Workerman\Protocols\Http\Response;

/**
 * Benchmarks StaticFilesMiddleware::__invoke — the static asset hot path.
 *
 * The per-path-component block check (denylist, backup-suffix rules,
 * allowlist) runs on every request that resolves to an existing file, so
 * regressions here translate directly into per-request latency for
 * legitimate assets.
 *
 * @BeforeMethods("init")
 * @AfterMethods("tearDown")
 * @Revs(1000)
 * @Iterations(5)
 * @Warmup(1)
 */
final class StaticFilesMiddlewareBench
{
    private string $rootDirectory;
    private StaticFilesMiddleware $middleware;
    private StaticFilesMiddleware $allowlistMiddleware;
    private Request $assetRequest;
    private Request $nestedAssetRequest;
    private Request $blockedBackupRequest;

    public function init(): void
    {
        $this->rootDirectory = sys_get_temp_dir() . '/static-files-bench-' . bin2hex(random_bytes(6));
        mkdir($this->rootDirectory, 0777, true);
        mkdir($this->rootDirectory . '/assets', 0777, true);
        file_put_contents($this->rootDirectory . '/style.css', 'body { color: red; }');
        file_put_contents($this->rootDirectory . '/assets/logo.png', 'png');
        file_put_contents($this->rootDirectory . '/index.php.bak', '<?php $DB_PASS = "s3cr3t";');

        $this->middleware = new StaticFilesMiddleware($this->rootDirectory);
        $this->allowlistMiddleware = new StaticFilesMiddleware(
            $this->rootDirectory,
            ['css', 'png'],
        );

        $this->assetRequest = new Request("GET /style.css HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $this->nestedAssetRequest = new Request("GET /assets/logo.png HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $this->blockedBackupRequest = new Request("GET /index.php.bak HTTP/1.1\r\nHost: localhost\r\n\r\n");
    }

    public function tearDown(): void
    {
        $this->removeDirectory($this->rootDirectory);
    }

    private function removeDirectory(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function benchServedAsset(): void
    {
        ($this->middleware)($this->assetRequest, $this->next(...));
    }

    public function benchServedNestedAsset(): void
    {
        ($this->middleware)($this->nestedAssetRequest, $this->next(...));
    }

    public function benchServedAssetWithAllowlist(): void
    {
        ($this->allowlistMiddleware)($this->assetRequest, $this->next(...));
    }

    public function benchBlockedBackupFile(): void
    {
        ($this->middleware)($this->blockedBackupRequest, $this->next(...));
    }

    private function next(): Response
    {
        return new Response(404);
    }
}
