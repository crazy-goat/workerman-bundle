<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\App;

use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;
use Workerman\Protocols\Http\Response;

/**
 * Counting middleware used by MiddlewareDispatchContractTest to assert the
 * cross-platform contract that the middleware pipeline dispatches each
 * middleware exactly once per HTTP request.
 *
 * Each __invoke increments a shared counter file under flock() so the parent
 * PHPUnit process can observe the dispatch count regardless of which forked
 * Workerman child handled the request. The current count is also exposed via
 * the X-Dispatch-Count response header for direct per-request inspection.
 *
 * The counter file path is injected by the test kernel as
 * %kernel.project_dir%/var/dispatch_count so it is shared between the
 * Workerman child processes and the PHPUnit parent process.
 */
final readonly class DispatchCountMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $counterFile,
    ) {
    }

    public function __invoke(Request $request, callable $next): Response
    {
        $count = $this->increment();

        $response = $next($request);
        $response->header('X-Dispatch-Count', (string) $count);

        return $response;
    }

    /**
     * Atomically read-and-increment the shared counter under an exclusive
     * flock(). Returns the post-increment value.
     */
    private function increment(): int
    {
        $dir = dirname($this->counterFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }

        $handle = fopen($this->counterFile, 'c+');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Cannot open dispatch counter file: %s', $this->counterFile));
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException(sprintf('Cannot lock dispatch counter file: %s', $this->counterFile));
            }

            rewind($handle);
            $raw = stream_get_contents($handle);
            $current = ($raw === false || $raw === '') ? 0 : (int) trim($raw);
            $next = $current + 1;

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, (string) $next);
            fflush($handle);
            flock($handle, LOCK_UN);

            return $next;
        } finally {
            fclose($handle);
        }
    }
}
