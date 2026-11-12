<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\App;

use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;
use Workerman\Protocols\Http\Response;

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
