<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Benchmark;

use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\DefaultResponseStrategy;
use PhpBench\Benchmark\Metadata\Annotations\BeforeMethods;
use PhpBench\Benchmark\Metadata\Annotations\Iterations;
use PhpBench\Benchmark\Metadata\Annotations\Revs;
use PhpBench\Benchmark\Metadata\Annotations\Warmup;
use Symfony\Component\HttpFoundation\Response;
use Workerman\Connection\TcpConnection;

/**
 * Benchmark ResponseConverter::convert (and the inner extractHeaders) —
 * the stage where a Symfony Response is transformed into a Workerman Response.
 *
 * The header-normalisation cache is the key optimisation here; the benchmark
 * exercises both cold-cache and warm-cache paths.
 *
 * @BeforeMethods("init")
 * @Revs(1000)
 * @Iterations(5)
 * @Warmup(1)
 */
final class ResponseConverterBench
{
    private ResponseConverter $converter;
    private TcpConnection $connection;
    private Response $simpleResponse;
    private Response $headerHeavyResponse;
    private Response $irregularHeadersResponse;

    public function init(): void
    {
        $this->converter = new ResponseConverter([new DefaultResponseStrategy()]);
        $this->connection = new BenchTcpConnection();

        $this->simpleResponse = new Response('Hello World', Response::HTTP_OK, [
            'Content-Type' => 'text/plain',
        ]);

        $this->headerHeavyResponse = new Response('Hello World', Response::HTTP_OK, [
            'Content-Type' => 'application/json',
            'X-Rate-Limit' => '100',
            'X-Request-Id' => 'uuid-1234',
            'Cache-Control' => 'no-cache',
            'Vary' => 'Accept-Encoding',
        ]);

        $this->irregularHeadersResponse = new Response('Hello World', Response::HTTP_OK, [
            'etag' => '"abc123"',
            'content-md5' => 'deadbeef',
            'www-authenticate' => 'Bearer',
            'dnt' => '1',
        ]);
    }

    public function benchSimpleResponse(): void
    {
        $this->converter->convert($this->simpleResponse, $this->connection);
    }

    public function benchHeaderHeavyResponse(): void
    {
        $this->converter->convert($this->headerHeavyResponse, $this->connection);
    }

    public function benchIrregularHeadersResponse(): void
    {
        $this->converter->convert($this->irregularHeadersResponse, $this->connection);
    }
}
