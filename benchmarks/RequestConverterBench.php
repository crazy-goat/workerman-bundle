<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Benchmark;

use CrazyGoat\WorkermanBundle\DTO\RequestConverter;
use CrazyGoat\WorkermanBundle\Http\Request;
use PhpBench\Benchmark\Metadata\Annotations\BeforeMethods;
use PhpBench\Benchmark\Metadata\Annotations\Iterations;
use PhpBench\Benchmark\Metadata\Annotations\Revs;
use PhpBench\Benchmark\Metadata\Annotations\Warmup;

/**
 * Benchmark RequestConverter::toSymfonyRequest — the first hot-path stage
 * where a Workerman HTTP request is transformed into a Symfony Request.
 *
 * @BeforeMethods("init")
 * @Revs(1000)
 * @Iterations(5)
 * @Warmup(1)
 */
final class RequestConverterBench
{
    private Request $simpleRequest;
    private Request $headerHeavyRequest;
    private Request $multipartRequest;

    public function init(): void
    {
        $this->simpleRequest = new Request("GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n");

        $buffer = "GET /test HTTP/1.1\r\n";
        $buffer .= "Host: example.com\r\n";
        $buffer .= "Accept: application/json\r\n";
        $buffer .= "Authorization: Basic dXNlcjpwYXNz\r\n";
        $buffer .= "Content-Type: application/json\r\n";
        $buffer .= "Content-Length: 123\r\n";
        $buffer .= "X-Custom: custom-value\r\n";
        $buffer .= "Cookie: session=abc123\r\n";
        $buffer .= "\r\n";
        $this->headerHeavyRequest = new Request($buffer);

        $body = '';
        $body .= "--TestBoundary\r\n";
        $body .= "Content-Disposition: form-data; name=\"test_file\"; filename=\"test.txt\"\r\n";
        $body .= "Content-Type: text/plain\r\n\r\n";
        $body .= "test content\r\n";
        $body .= "--TestBoundary--\r\n";

        $buffer = "POST /test HTTP/1.1\r\n";
        $buffer .= "Host: localhost\r\n";
        $buffer .= "Content-Type: multipart/form-data; boundary=TestBoundary\r\n";
        $buffer .= 'Content-Length: ' . strlen($body) . "\r\n";
        $buffer .= "\r\n";
        $this->multipartRequest = new Request($buffer . $body);
    }

    public function benchSimpleRequest(): void
    {
        RequestConverter::toSymfonyRequest($this->simpleRequest);
    }

    public function benchHeaderHeavyRequest(): void
    {
        RequestConverter::toSymfonyRequest($this->headerHeavyRequest);
    }

    public function benchMultipartRequest(): void
    {
        RequestConverter::toSymfonyRequest($this->multipartRequest);
    }
}
