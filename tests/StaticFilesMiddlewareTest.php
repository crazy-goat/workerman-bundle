<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Middleware\StaticFilesMiddleware;
use PHPUnit\Framework\TestCase;
use Workerman\Protocols\Http\Response;

final class StaticFilesMiddlewareTest extends TestCase
{
    private string $rootDirectory;

    protected function setUp(): void
    {
        $this->rootDirectory = __DIR__ . '/data/public';
        if (!is_dir($this->rootDirectory)) {
            mkdir($this->rootDirectory, 0777, true);
        }
        file_put_contents($this->rootDirectory . '/test.txt', 'test file content');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->rootDirectory . '/test.txt')) {
            unlink($this->rootDirectory . '/test.txt');
        }
        // Clean up the test directory
        if (is_dir($this->rootDirectory)) {
            rmdir($this->rootDirectory);
        }
    }

    /**
     * @dataProvider invalidCharacterProvider
     */
    public function testInvalidCharactersPassToNext(string $path): void
    {
        $middleware = new StaticFilesMiddleware($this->rootDirectory);

        $request = $this->createRequest($path);
        $called = false;
        $next = function (Request $req) use (&$called): Response {
            $called = true;
            return new Response(200);
        };

        $response = $middleware($request, $next);

        $this->assertTrue($called, "Next should be called for invalid path: $path");
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidCharacterProvider(): array
    {
        return [
            'NUL byte in path' => ["/test.txt\0"],
            'URL-encoded NUL prefix' => ['/%00/../etc/passwd'],
            'backslash in path' => ['/..\\test.txt'],
            'encoded backslash in path' => ['/%5C/../etc/passwd'],
        ];
    }

    public function testPrefixCollisionBlocked(): void
    {
        $siblingDir = dirname($this->rootDirectory) . '/public-other';
        @mkdir($siblingDir, 0777, true);

        try {
            $middleware = new StaticFilesMiddleware($this->rootDirectory);

            $request = $this->createRequest('/../public-other/test.txt');
            $called = false;
            $next = function (Request $req) use (&$called): Response {
                $called = true;
                return new Response(200);
            };

            $response = $middleware($request, $next);

            $this->assertTrue($called, 'Next should be called for prefix collision path');
            $this->assertEquals(200, $response->getStatusCode());
        } finally {
            if (is_dir($siblingDir)) {
                rmdir($siblingDir);
            }
        }
    }

    public function testSymlinkEscapingBlocked(): void
    {
        $targetDir = __DIR__ . '/data/outside';
        $linkPath = $this->rootDirectory . '/escape_link';

        try {
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0777, true);
            }
            file_put_contents($targetDir . '/secret.txt', 'secret content');

            if (!file_exists($linkPath)) {
                symlink($targetDir, $linkPath);
            }

            $middleware = new StaticFilesMiddleware($this->rootDirectory);

            $request = $this->createRequest('/escape_link/secret.txt');
            $called = false;
            $next = function (Request $req) use (&$called): Response {
                $called = true;
                return new Response(200);
            };

            $response = $middleware($request, $next);

            $this->assertTrue($called, 'Next should be called for symlink escaping path');
            $this->assertEquals(200, $response->getStatusCode());
        } finally {
            if (file_exists($linkPath)) {
                unlink($linkPath);
            }
            if (file_exists($targetDir . '/secret.txt')) {
                unlink($targetDir . '/secret.txt');
            }
            if (is_dir($targetDir)) {
                rmdir($targetDir);
            }
        }
    }

    public function testSymlinkInsideRootAllowedWhenFollowSymlinksEnabled(): void
    {
        $linkPath = $this->rootDirectory . '/linked';
        $subDir = $this->rootDirectory . '/subdir';

        try {
            if (!is_dir($subDir)) {
                mkdir($subDir, 0777, true);
            }
            file_put_contents($subDir . '/linked_file.txt', 'linked content');

            if (!file_exists($linkPath)) {
                symlink($subDir, $linkPath);
            }

            $middleware = new StaticFilesMiddleware($this->rootDirectory, [], true);

            $request = $this->createRequest('/linked/linked_file.txt');
            $called = false;
            $next = function (Request $req) use (&$called): Response {
                $called = true;
                return new Response(404);
            };

            $middleware($request, $next);

            $this->assertFalse($called, 'Next should not be called for symlink inside root when follow_symlinks enabled');
        } finally {
            if (file_exists($linkPath)) {
                unlink($linkPath);
            }
            if (file_exists($subDir . '/linked_file.txt')) {
                unlink($subDir . '/linked_file.txt');
            }
            if (is_dir($subDir)) {
                rmdir($subDir);
            }
        }
    }

    public function testSymlinkInsideRootBlockedByDefault(): void
    {
        $linkPath = $this->rootDirectory . '/linked';
        $subDir = $this->rootDirectory . '/subdir';

        try {
            if (!is_dir($subDir)) {
                mkdir($subDir, 0777, true);
            }
            file_put_contents($subDir . '/linked_file.txt', 'linked content');

            if (!file_exists($linkPath)) {
                symlink($subDir, $linkPath);
            }

            $middleware = new StaticFilesMiddleware($this->rootDirectory);

            $request = $this->createRequest('/linked/linked_file.txt');
            $called = false;
            $next = function (Request $req) use (&$called): Response {
                $called = true;
                return new Response(404);
            };

            $response = $middleware($request, $next);

            $this->assertTrue($called, 'Next should be called for symlink when follow_symlinks is disabled');
            $this->assertEquals(404, $response->getStatusCode());
        } finally {
            if (file_exists($linkPath)) {
                unlink($linkPath);
            }
            if (file_exists($subDir . '/linked_file.txt')) {
                unlink($subDir . '/linked_file.txt');
            }
            if (is_dir($subDir)) {
                rmdir($subDir);
            }
        }
    }

    public function testRegularFileStillServedWithFollowSymlinksDisabled(): void
    {
        $middleware = new StaticFilesMiddleware($this->rootDirectory);

        $request = $this->createRequest('/test.txt');
        $called = false;
        $next = function (Request $req) use (&$called): Response {
            $called = true;
            return new Response(404);
        };

        $middleware($request, $next);

        $this->assertFalse($called, 'Next should not be called for regular file with follow_symlinks disabled');
    }

    public function testSymlinkEscapingBlockedWithFollowSymlinksEnabled(): void
    {
        $targetDir = __DIR__ . '/data/outside';
        $linkPath = $this->rootDirectory . '/escape_link';

        try {
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0777, true);
            }
            file_put_contents($targetDir . '/secret.txt', 'secret content');

            if (!file_exists($linkPath)) {
                symlink($targetDir, $linkPath);
            }

            $middleware = new StaticFilesMiddleware($this->rootDirectory, [], true);

            $request = $this->createRequest('/escape_link/secret.txt');
            $called = false;
            $next = function (Request $req) use (&$called): Response {
                $called = true;
                return new Response(200);
            };

            $response = $middleware($request, $next);

            $this->assertTrue($called, 'Next should be called for symlink escaping path even with follow_symlinks enabled');
            $this->assertEquals(200, $response->getStatusCode());
        } finally {
            if (file_exists($linkPath)) {
                unlink($linkPath);
            }
            if (file_exists($targetDir . '/secret.txt')) {
                unlink($targetDir . '/secret.txt');
            }
            if (is_dir($targetDir)) {
                rmdir($targetDir);
            }
        }
    }

    /**
     * @dataProvider pathTraversalProvider
     */
    public function testPathTraversalBlocked(string $path): void
    {
        $middleware = new StaticFilesMiddleware($this->rootDirectory);

        $request = $this->createRequest($path);
        $called = false;
        $next = function (Request $req) use (&$called): Response {
            $called = true;
            return new Response(200);
        };

        $response = $middleware($request, $next);

        // Next should be called (file not served)
        $this->assertTrue($called, "Next should be called for path: $path");
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pathTraversalProvider(): array
    {
        return [
            'classic path traversal' => ['../../../etc/passwd'],
            'pattern with dots and slashes' => ['....//etc/passwd'],
            'multiple dot combinations' => ['....//....//etc/passwd'],
            // Note: URL-encoded payloads test defense-in-depth.
            // Workerman\Request::path() returns the raw path without URL-decoding,
            // so realpath() receives literal strings like '%2e%2e%2f...'.
            // These tests ensure that even if URL-decoding happened elsewhere,
            // the path traversal protection remains effective.
            'url encoded dots' => ['%2e%2e%2f%2e%2e%2f%2e%2e%2fetc%2fpasswd'],
            'double url encoded' => ['%252e%252e%252f%252e%252e%252f%252e%252e%252fetc%252fpasswd'],
            'path traversal in middle' => ['test.txt/../../../etc/passwd'],
            'dot dot slash at start' => ['../readme.txt'],
        ];
    }

    public function testValidFileServed(): void
    {
        $middleware = new StaticFilesMiddleware($this->rootDirectory);

        $request = $this->createRequest('/test.txt');
        $called = false;
        $next = function (Request $req) use (&$called): Response {
            $called = true;
            return new Response(404);
        };

        $middleware($request, $next);

        // Next should NOT be called (file should be served)
        $this->assertFalse($called, "Next should not be called for valid file");
    }

    public function testNonExistentFilePassesToNext(): void
    {
        $middleware = new StaticFilesMiddleware($this->rootDirectory);

        $request = $this->createRequest('/nonexistent.txt');
        $called = false;
        $next = function (Request $req) use (&$called): Response {
            $called = true;
            return new Response(404);
        };

        $response = $middleware($request, $next);

        $this->assertTrue($called, "Next should be called for non-existent file");
        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testSymlinkNegativeCacheRespectsMaxSize(): void
    {
        $linkPath = $this->rootDirectory . '/assets';
        $subDir = $this->rootDirectory . '/realdir';

        $middleware = new StaticFilesMiddleware($this->rootDirectory);
        $next = fn(Request $req): Response => new Response(404);

        try {
            if (!is_dir($subDir)) {
                mkdir($subDir, 0777, true);
            }
            if (!file_exists($linkPath)) {
                symlink($subDir, $linkPath);
            }

            $cacheCount = function () use ($middleware): int {
                $method = new \ReflectionMethod($middleware, 'getRealPathCache');

                return count($method->invoke($middleware));
            };

            $maxSizeReflection = new \ReflectionClassConstant(StaticFilesMiddleware::class, 'CACHE_MAX_SIZE');
            $maxSize = $maxSizeReflection->getValue();
            assert(is_int($maxSize));

            $requestAt = fn(int $i): Request => $this->createRequest(sprintf('/assets/pad-%d.css', $i));
            $batchSize = 5 * $maxSize;

            // First batch: 5x the cap worth of unique symlink-traversing paths.
            for ($i = 0; $i < $batchSize; $i++) {
                $middleware($requestAt($i), $next);
            }
            $countAfterFirstBatch = $cacheCount();

            $this->assertLessThanOrEqual($maxSize, $countAfterFirstBatch, 'Negative cache must never exceed CACHE_MAX_SIZE');

            // Second batch: entry count must stay stable, not grow linearly with request count.
            for ($i = $batchSize; $i < 2 * $batchSize; $i++) {
                $middleware($requestAt($i), $next);
            }
            $this->assertSame($countAfterFirstBatch, $cacheCount(), 'Cache size must stay stable across batches');

            // Repeated lookups of a rejected path must still fall through to $next.
            $called = false;
            $nextBlocking = function (Request $req) use (&$called): Response {
                $called = true;

                return new Response(404);
            };
            $middleware($requestAt(0), $nextBlocking);

            $this->assertTrue($called, 'Symlink-traversing path should still be rejected');
        } finally {
            if (file_exists($linkPath)) {
                unlink($linkPath);
            }
            if (is_dir($subDir)) {
                rmdir($subDir);
            }
        }
    }

    public function testSymlinkRejectionHitKeepsFixedTtl(): void
    {
        $linkPath = $this->rootDirectory . '/assets';
        $subDir = $this->rootDirectory . '/realdir';

        $middleware = new StaticFilesMiddleware($this->rootDirectory);

        try {
            if (!is_dir($subDir)) {
                mkdir($subDir, 0777, true);
            }
            if (!file_exists($linkPath)) {
                symlink($subDir, $linkPath);
            }

            $path = '/assets/secret.txt';
            $called = 0;
            $next = function (Request $req) use (&$called): Response {
                $called++;

                return new Response(404);
            };

            $cacheIndex = function () use ($middleware, $path): string {
                $rootRealPathReflection = new \ReflectionProperty($middleware, 'rootRealPath');
                $rootRealPath = $rootRealPathReflection->getValue($middleware);
                assert(is_string($rootRealPath));

                return $path . "\0" . '0' . "\0" . $rootRealPath;
            };

            $storedTime = function () use ($middleware, $cacheIndex): ?int {
                $cacheMethod = new \ReflectionMethod($middleware, 'getRealPathCache');
                $cache = $cacheMethod->invoke($middleware);
                assert(is_array($cache));
                $entry = $cache[$cacheIndex()] ?? null;

                return is_array($entry) && is_int($entry['time']) ? $entry['time'] : null;
            };

            // Warm the negative cache.
            $middleware($this->createRequest($path), $next);
            $timeAfterWarm = $storedTime();
            $this->assertNotNull($timeAfterWarm, 'Rejected path should be present in the negative cache');

            // A hit within CACHE_NEGATIVE_TTL must not slide the expiry forward.
            sleep(1);
            $middleware($this->createRequest($path), $next);

            $this->assertSame($timeAfterWarm, $storedTime(), 'A cache hit must not refresh the TTL timestamp');
            $this->assertSame(2, $called, 'Every symlink-traversing request must fall through to $next');
        } finally {
            if (file_exists($linkPath)) {
                unlink($linkPath);
            }
            if (is_dir($subDir)) {
                rmdir($subDir);
            }
        }
    }

    private function createRequest(string $path): Request
    {
        $buffer = "GET $path HTTP/1.1\r\nHost: localhost\r\n\r\n";
        return new Request($buffer);
    }

    public function testDotfilePathComponentBlockedReturns404(): void
    {
        $middleware = new StaticFilesMiddleware($this->rootDirectory);

        $hiddenDir = $this->rootDirectory . '/.hidden';
        if (!is_dir($hiddenDir)) {
            mkdir($hiddenDir, 0777, true);
        }
        file_put_contents($hiddenDir . '/test.txt', 'hidden file');

        try {
            $request = $this->createRequest('/.hidden/test.txt');
            $called = false;
            $next = function (Request $req) use (&$called): Response {
                $called = true;
                return new Response(404);
            };

            $response = $middleware($request, $next);

            $this->assertFalse($called, 'Next should NOT be called for dotfile path component');
            $this->assertEquals(404, $response->getStatusCode(), 'Should return 404 for dotfile');
        } finally {
            unlink($hiddenDir . '/test.txt');
            rmdir($hiddenDir);
        }
    }

    public function testNestedHtaccessBlockedReturns404(): void
    {
        $middleware = new StaticFilesMiddleware($this->rootDirectory);
        $nestedDir = $this->rootDirectory . '/nested';
        mkdir($nestedDir);
        file_put_contents($nestedDir . '/.htaccess', 'deny all');

        try {
            $request = $this->createRequest('/nested/.htaccess');
            $called = false;
            $next = function (Request $req) use (&$called): Response {
                $called = true;
                return new Response(404);
            };

            $response = $middleware($request, $next);

            $this->assertFalse($called, 'Next should NOT be called for nested .htaccess');
            $this->assertEquals(404, $response->getStatusCode(), 'Should return 404 for nested .htaccess');

            $rootPathReflection = new \ReflectionProperty($middleware, 'rootRealPath');
            $rootPath = $rootPathReflection->getValue($middleware);
            $this->assertIsString($rootPath);
            $pathReflection = new \ReflectionMethod($middleware, 'isFilePathBlocked');
            $this->assertTrue($pathReflection->invoke($middleware, $rootPath . '\\nested\\.htaccess'));
        } finally {
            unlink($nestedDir . '/.htaccess');
            rmdir($nestedDir);
        }
    }

    public function testDotfileBlockedReturns404(): void
    {
        $dotfile = $this->rootDirectory . '/.secret';
        file_put_contents($dotfile, 'secret');

        try {
            $middleware = new StaticFilesMiddleware($this->rootDirectory);

            $request = $this->createRequest('/.secret');
            $called = false;
            $next = function (Request $req) use (&$called): Response {
                $called = true;
                return new Response(404);
            };

            $response = $middleware($request, $next);

            $this->assertFalse($called, 'Next should NOT be called for dotfile');
            $this->assertEquals(404, $response->getStatusCode(), 'Should return 404');
        } finally {
            unlink($dotfile);
        }
    }

    public function testPhpFileBlockedReturns404(): void
    {
        $phpFile = $this->rootDirectory . '/malicious.php';
        file_put_contents($phpFile, '<?php echo "hacked";');

        try {
            $middleware = new StaticFilesMiddleware($this->rootDirectory);

            $request = $this->createRequest('/malicious.php');
            $called = false;
            $next = function (Request $req) use (&$called): Response {
                $called = true;
                return new Response(404);
            };

            $response = $middleware($request, $next);

            $this->assertFalse($called, 'Next should NOT be called for .php file');
            $this->assertEquals(404, $response->getStatusCode(), 'Should return 404 for .php');
        } finally {
            unlink($phpFile);
        }
    }

    public function testEnvironFileBlockedReturns404(): void
    {
        $envFile = $this->rootDirectory . '/.env';
        file_put_contents($envFile, 'DB_PASSWORD=secret');

        try {
            $middleware = new StaticFilesMiddleware($this->rootDirectory);

            $request = $this->createRequest('/.env');
            $called = false;
            $next = function (Request $req) use (&$called): Response {
                $called = true;
                return new Response(404);
            };

            $response = $middleware($request, $next);

            $this->assertFalse($called, 'Next should NOT be called for .env file');
            $this->assertEquals(404, $response->getStatusCode(), 'Should return 404 for .env');
        } finally {
            unlink($envFile);
        }
    }

    public function testEnvironProdFileBlockedReturns404(): void
    {
        $envProdFile = $this->rootDirectory . '/.env.prod';
        file_put_contents($envProdFile, 'DB_PASSWORD=prod-secret');

        try {
            $middleware = new StaticFilesMiddleware($this->rootDirectory);

            $request = $this->createRequest('/.env.prod');
            $called = false;
            $next = function (Request $req) use (&$called): Response {
                $called = true;
                return new Response(404);
            };

            $response = $middleware($request, $next);

            $this->assertFalse($called, 'Next should NOT be called for .env.prod file');
            $this->assertEquals(404, $response->getStatusCode(), 'Should return 404 for .env.prod');
        } finally {
            unlink($envProdFile);
        }
    }

    public function testComposerFilesBlockedReturns404(): void
    {
        $composerFile = $this->rootDirectory . '/composer.json';
        file_put_contents($composerFile, '{"name": "test"}');

        try {
            $middleware = new StaticFilesMiddleware($this->rootDirectory);

            $request = $this->createRequest('/composer.json');
            $called = false;
            $next = function (Request $req) use (&$called): Response {
                $called = true;
                return new Response(404);
            };

            $response = $middleware($request, $next);

            $this->assertFalse($called, 'Next should NOT be called for composer.json');
            $this->assertEquals(404, $response->getStatusCode(), 'Should return 404 for composer.json');
        } finally {
            unlink($composerFile);
        }
    }

    public function testComposerLockBlockedReturns404(): void
    {
        $composerLockFile = $this->rootDirectory . '/composer.lock';
        file_put_contents($composerLockFile, '{"packages": []}');

        try {
            $middleware = new StaticFilesMiddleware($this->rootDirectory);

            $request = $this->createRequest('/composer.lock');
            $called = false;
            $next = function (Request $req) use (&$called): Response {
                $called = true;
                return new Response(404);
            };

            $response = $middleware($request, $next);

            $this->assertFalse($called, 'Next should NOT be called for composer.lock');
            $this->assertEquals(404, $response->getStatusCode(), 'Should return 404 for composer.lock');
        } finally {
            unlink($composerLockFile);
        }
    }

    public function testGitBlobBlockedReturns404(): void
    {
        $gitDir = $this->rootDirectory . '/.git';
        if (!is_dir($gitDir)) {
            mkdir($gitDir, 0777, true);
        }
        file_put_contents($gitDir . '/HEAD', 'ref: refs/heads/main');

        try {
            $middleware = new StaticFilesMiddleware($this->rootDirectory);

            $request = $this->createRequest('/.git/HEAD');
            $called = false;
            $next = function (Request $req) use (&$called): Response {
                $called = true;
                return new Response(404);
            };

            $response = $middleware($request, $next);

            $this->assertFalse($called, 'Next should NOT be called for .git/HEAD');
            $this->assertEquals(404, $response->getStatusCode(), 'Should return 404 for .git objects');
        } finally {
            unlink($gitDir . '/HEAD');
            rmdir($gitDir);
        }
    }

    /**
     * @dataProvider blockedExtensionProvider
     */
    public function testBlockedExtensionsReturn404(string $fileName, string $extension): void
    {
        $file = $this->rootDirectory . '/' . $fileName;
        file_put_contents($file, 'x');

        try {
            $middleware = new StaticFilesMiddleware($this->rootDirectory);

            $request = $this->createRequest('/' . $fileName);
            $called = false;
            $next = function (Request $req) use (&$called): Response {
                $called = true;
                return new Response(404);
            };

            $response = $middleware($request, $next);

            $this->assertFalse($called, "Next should NOT be called for .$extension file");
            $this->assertEquals(404, $response->getStatusCode(), "Should return 404 for .$extension");
        } finally {
            unlink($file);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function blockedExtensionProvider(): array
    {
        return [
            'PHP file' => ['test.php', 'php'],
            'PHAR file' => ['app.phar', 'phar'],
            'PHTML file' => ['index.phtml', 'phtml'],
        ];
    }

    /**
     * @dataProvider blockedExtensionProvider
     */
    public function testBlockedExtensionsTakePrecedenceOverAllowlist(string $fileName, string $extension): void
    {
        $file = $this->rootDirectory . '/' . $fileName;
        file_put_contents($file, 'x');

        try {
            $middleware = new StaticFilesMiddleware($this->rootDirectory, [$extension]);
            $request = $this->createRequest('/' . $fileName);
            $called = false;
            $next = function (Request $req) use (&$called): Response {
                $called = true;
                return new Response(404);
            };

            $response = $middleware($request, $next);

            $this->assertFalse($called, "Next should NOT be called for .$extension file");
            $this->assertEquals(404, $response->getStatusCode(), "Should return 404 for .$extension");
        } finally {
            unlink($file);
        }
    }

    public function testAllowedExtensionsWhitelistServing(): void
    {
        $cssFile = $this->rootDirectory . '/style.css';
        file_put_contents($cssFile, 'body { color: red; }');

        try {
            $middleware = new StaticFilesMiddleware($this->rootDirectory, ['css', 'js', 'png']);

            $request = $this->createRequest('/style.css');
            $called = false;
            $next = function (Request $req) use (&$called): Response {
                $called = true;
                return new Response(404);
            };

            $middleware($request, $next);

            $this->assertFalse($called, 'Next should NOT be called for allowed extension');
        } finally {
            unlink($cssFile);
        }
    }

    public function testAllowedExtensionsWhitelistBlocking(): void
    {
        $jsonFile = $this->rootDirectory . '/data.json';
        file_put_contents($jsonFile, '{"key": "value"}');

        try {
            $middleware = new StaticFilesMiddleware($this->rootDirectory, ['css', 'js', 'png']);

            $request = $this->createRequest('/data.json');
            $called = false;
            $next = function (Request $req) use (&$called): Response {
                $called = true;
                return new Response(404);
            };

            $response = $middleware($request, $next);

            $this->assertFalse($called, 'Next should NOT be called for disallowed extension');
            $this->assertEquals(404, $response->getStatusCode(), 'Should return 404 for disallowed extension');
        } finally {
            unlink($jsonFile);
        }
    }

    /**
     * @dataProvider extensionlessFileProvider
     */
    public function testExtensionlessFilesAreBlockedWithAllowlist(string $fileName): void
    {
        $file = $this->rootDirectory . '/' . $fileName;
        file_put_contents($file, 'sensitive content');

        try {
            $middleware = new StaticFilesMiddleware($this->rootDirectory, ['css', 'js', 'png']);

            $request = $this->createRequest('/' . $fileName);
            $called = false;
            $next = function (Request $req) use (&$called): Response {
                $called = true;
                return new Response(404);
            };

            $response = $middleware($request, $next);

            $this->assertFalse($called, "Next should NOT be called for extensionless file: $fileName");
            $this->assertEquals(404, $response->getStatusCode(), "Should return 404 for extensionless file: $fileName");
        } finally {
            unlink($file);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function extensionlessFileProvider(): array
    {
        return [
            'Dockerfile' => ['Dockerfile'],
            'SSH private key' => ['id_rsa'],
            'Database dump' => ['dump'],
        ];
    }

    public function testFileNameEndingWithDotIsBlockedByAllowlist(): void
    {
        $middleware = new StaticFilesMiddleware($this->rootDirectory, ['css', 'js', 'png']);

        if (DIRECTORY_SEPARATOR === '\\') {
            $reflection = new \ReflectionMethod($middleware, 'isComponentBlocked');
            $this->assertTrue($reflection->invoke($middleware, 'index.php.'));

            return;
        }

        $file = $this->rootDirectory . '/index.php.';
        file_put_contents($file, 'sensitive content');

        try {
            $called = false;
            $next = function (Request $req) use (&$called): Response {
                $called = true;
                return new Response(404);
            };

            $response = $middleware($this->createRequest('/index.php.'), $next);

            $this->assertFalse($called, 'Next should NOT be called for a filename ending in a dot');
            $this->assertEquals(404, $response->getStatusCode());
        } finally {
            unlink($file);
        }
    }

    public function testAllowlistBlockedFileIsIndistinguishableFromMissingFile(): void
    {
        $blockedFile = $this->rootDirectory . '/Dockerfile';
        file_put_contents($blockedFile, 'sensitive content');

        try {
            $middleware = new StaticFilesMiddleware($this->rootDirectory, ['css', 'js', 'png']);
            $next = fn(Request $req): Response => new Response(404);

            $blockedResponse = $middleware($this->createRequest('/Dockerfile'), $next);
            $missingResponse = $middleware($this->createRequest('/missing'), $next);

            $this->assertSame($missingResponse->getStatusCode(), $blockedResponse->getStatusCode());
            $this->assertSame($missingResponse->rawBody(), $blockedResponse->rawBody());
        } finally {
            unlink($blockedFile);
        }
    }

    public function testValidFileStillServed(): void
    {
        $middleware = new StaticFilesMiddleware($this->rootDirectory);

        $request = $this->createRequest('/test.txt');
        $called = false;
        $next = function (Request $req) use (&$called): Response {
            $called = true;
            return new Response(404);
        };

        $middleware($request, $next);

        $this->assertFalse($called, 'Next should not be called for valid file');
    }

    public function testValidFileStillServedWithAllowlist(): void
    {
        $middleware = new StaticFilesMiddleware($this->rootDirectory, ['txt', 'css', 'js']);

        $request = $this->createRequest('/test.txt');
        $called = false;
        $next = function (Request $req) use (&$called): Response {
            $called = true;
            return new Response(404);
        };

        $middleware($request, $next);

        $this->assertFalse($called, 'Next should not be called for valid file with allowlist');
    }

    public function testUpperCaseComposerJsonBlocked(): void
    {
        $composerFile = $this->rootDirectory . '/Composer.json';
        file_put_contents($composerFile, '{"name": "test"}');

        try {
            $middleware = new StaticFilesMiddleware($this->rootDirectory);

            $request = $this->createRequest('/Composer.json');
            $called = false;
            $next = function (Request $req) use (&$called): Response {
                $called = true;
                return new Response(404);
            };

            $response = $middleware($request, $next);

            $this->assertFalse($called, 'Next should NOT be called for uppercase Composer.json');
            $this->assertEquals(404, $response->getStatusCode(), 'Should return 404 for uppercase Composer.json');
        } finally {
            unlink($composerFile);
        }
    }

    public function testMixedCasePackageJsonBlocked(): void
    {
        $packageFile = $this->rootDirectory . '/Package.json';
        file_put_contents($packageFile, '{"name": "test"}');

        try {
            $middleware = new StaticFilesMiddleware($this->rootDirectory);

            $request = $this->createRequest('/Package.json');
            $called = false;
            $next = function (Request $req) use (&$called): Response {
                $called = true;
                return new Response(404);
            };

            $response = $middleware($request, $next);

            $this->assertFalse($called, 'Next should NOT be called for Package.json');
            $this->assertEquals(404, $response->getStatusCode(), 'Should return 404 for Package.json');
        } finally {
            unlink($packageFile);
        }
    }

    public function testFileServedWithCacheHeaders(): void
    {
        $middleware = new StaticFilesMiddleware($this->rootDirectory);

        $request = $this->createRequest('/test.txt');
        $next = fn(): Response => new Response(404);

        $response = $middleware($request, $next);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertNotNull($response->getHeader('Last-Modified'));
        $this->assertNotNull($response->getHeader('ETag'));
        $this->assertEquals('public, max-age=3600, must-revalidate', $response->getHeader('Cache-Control'));
    }

    public function testNotModifiedWhenEtagMatches(): void
    {
        $middleware = new StaticFilesMiddleware($this->rootDirectory);

        $request = $this->createRequest('/test.txt');
        $next = fn(): Response => new Response(404);

        $response = $middleware($request, $next);
        $etag = $response->getHeader('ETag');
        assert(is_string($etag));

        $requestWithEtag = $this->createRequest('/test.txt');
        $requestWithEtag->setHeader('If-None-Match', $etag);

        $response304 = $middleware($requestWithEtag, $next);

        $this->assertEquals(304, $response304->getStatusCode());
    }

    public function testNotModifiedWhenIfModifiedSinceFresh(): void
    {
        $middleware = new StaticFilesMiddleware($this->rootDirectory);

        $request = $this->createRequest('/test.txt');
        $next = fn(): Response => new Response(404);

        $response = $middleware($request, $next);
        $lastModified = $response->getHeader('Last-Modified');
        assert(is_string($lastModified));

        $requestWithIMS = $this->createRequest('/test.txt');
        $requestWithIMS->setHeader('If-Modified-Since', $lastModified);

        $response304 = $middleware($requestWithIMS, $next);

        $this->assertEquals(304, $response304->getStatusCode());
    }

    public function testNotModifiedWhenIfModifiedSinceAfterModification(): void
    {
        $middleware = new StaticFilesMiddleware($this->rootDirectory);

        $request = $this->createRequest('/test.txt');
        $next = fn(): Response => new Response(404);

        $futureDate = gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT';

        $requestWithIMS = $this->createRequest('/test.txt');
        $requestWithIMS->setHeader('If-Modified-Since', $futureDate);

        $response304 = $middleware($requestWithIMS, $next);

        $this->assertEquals(304, $response304->getStatusCode());
    }

    public function testModifiedWhenEtagDoesNotMatch(): void
    {
        $middleware = new StaticFilesMiddleware($this->rootDirectory);

        $request = $this->createRequest('/test.txt');
        $next = fn(): Response => new Response(404);

        $requestWithBadEtag = $this->createRequest('/test.txt');
        $requestWithBadEtag->setHeader('If-None-Match', '"non-matching-etag"');

        $response = $middleware($requestWithBadEtag, $next);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testModifiedWhenIfModifiedSinceOlder(): void
    {
        $middleware = new StaticFilesMiddleware($this->rootDirectory);

        $request = $this->createRequest('/test.txt');
        $next = fn(): Response => new Response(404);

        $oldDate = gmdate('D, d M Y H:i:s', 0) . ' GMT';
        $requestWithOldIMS = $this->createRequest('/test.txt');
        $requestWithOldIMS->setHeader('If-Modified-Since', $oldDate);

        $response = $middleware($requestWithOldIMS, $next);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testConstructWithPharPathDoesNotThrow(): void
    {
        $middleware = new StaticFilesMiddleware('phar:///test/app.phar/public');

        $this->assertInstanceOf(StaticFilesMiddleware::class, $middleware);
    }

    public function testJoinPathsWithRootTrailingSlash(): void
    {
        $middleware = new StaticFilesMiddleware('phar:///test/app.phar/public');

        $reflection = new \ReflectionMethod($middleware, 'joinPaths');
        $result = $reflection->invoke($middleware, 'phar:///test/app.phar/public/', '/sub/file.txt');

        $this->assertSame('phar:///test/app.phar/public' . DIRECTORY_SEPARATOR . 'sub/file.txt', $result);
    }

    public function testJoinPathsWithRequestPathMissingLeadingSlash(): void
    {
        $middleware = new StaticFilesMiddleware('phar:///test/app.phar/public');

        $reflection = new \ReflectionMethod($middleware, 'joinPaths');

        $result = $reflection->invoke($middleware, 'phar:///test/app.phar/public', 'sub/file.txt');

        $this->assertSame('phar:///test/app.phar/public' . DIRECTORY_SEPARATOR . 'sub/file.txt', $result);
    }

    public function testJoinPathsWithBothRootTrailingSlashAndNoLeadingSlash(): void
    {
        $middleware = new StaticFilesMiddleware('phar:///test/app.phar/public');

        $reflection = new \ReflectionMethod($middleware, 'joinPaths');

        $result = $reflection->invoke($middleware, 'phar:///test/app.phar/public/', 'sub/file.txt');

        $this->assertSame('phar:///test/app.phar/public' . DIRECTORY_SEPARATOR . 'sub/file.txt', $result);
    }

    public function testJoinPathsWithRootNoTrailingSlashAndLeadingSlash(): void
    {
        $middleware = new StaticFilesMiddleware('phar:///test/app.phar/public');

        $reflection = new \ReflectionMethod($middleware, 'joinPaths');

        $result = $reflection->invoke($middleware, 'phar:///test/app.phar/public', '/sub/file.txt');

        $this->assertSame('phar:///test/app.phar/public' . DIRECTORY_SEPARATOR . 'sub/file.txt', $result);
    }

    public function testJoinPathsWithPharPathHavingTrailingSlashConstructed(): void
    {
        $middleware = new StaticFilesMiddleware('phar:///test/app.phar/public/');

        $reflection = new \ReflectionProperty($middleware, 'rootRealPath');
        $rootRealPath = $reflection->getValue($middleware);

        $this->assertStringEndsNotWith('/', $rootRealPath);
        $this->assertStringEndsNotWith('\\', $rootRealPath);
    }

    public function testJoinPathsWithFilesystemRoot(): void
    {
        $middleware = new StaticFilesMiddleware($this->rootDirectory);

        $reflection = new \ReflectionMethod($middleware, 'joinPaths');

        $root = realpath($this->rootDirectory);
        $result = $reflection->invoke($middleware, $root . '/', '/test.txt');

        $this->assertSame($root . DIRECTORY_SEPARATOR . 'test.txt', $result);
    }

    public function testPharPathNonExistentFilePassesToNext(): void
    {
        $middleware = new StaticFilesMiddleware('phar:///test/app.phar/public');

        $request = $this->createRequest('/test.txt');
        $called = false;
        $next = function (Request $req) use (&$called): Response {
            $called = true;
            return new Response(404);
        };

        $response = $middleware($request, $next);

        $this->assertTrue($called, 'Next should be called for non-existent file in phar path');
        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testNormalPathStillWorksAfterPharChanges(): void
    {
        $middleware = new StaticFilesMiddleware($this->rootDirectory);

        $request = $this->createRequest('/test.txt');
        $called = false;
        $next = function (Request $req) use (&$called): Response {
            $called = true;
            return new Response(404);
        };

        $middleware($request, $next);

        $this->assertFalse($called, 'Next should not be called for valid file after phar changes');
    }

    public function testPharPathInvalidCharactersStillBlocked(): void
    {
        $middleware = new StaticFilesMiddleware('phar:///test/app.phar/public');

        $request = $this->createRequest("/test.txt\0");
        $called = false;
        $next = function (Request $req) use (&$called): Response {
            $called = true;
            return new Response(200);
        };

        $response = $middleware($request, $next);

        $this->assertTrue($called, 'Next should be called for NUL byte path in phar');
        $this->assertEquals(200, $response->getStatusCode());
    }
}
