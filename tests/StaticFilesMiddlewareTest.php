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

    public function testSymlinkInsideRootAllowed(): void
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

            $middleware($request, $next);

            $this->assertFalse($called, 'Next should not be called for symlink inside root');
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

    private function createRequest(string $path): Request
    {
        // Create a proper HTTP request buffer
        $buffer = "GET $path HTTP/1.1\r\nHost: localhost\r\n\r\n";
        return new Request($buffer);
    }
}
