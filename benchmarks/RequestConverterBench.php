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
    /**
     * Every byte rejected in header values (reject {0-8, 10-31, 127}; TAB and
     * all other bytes accepted). Listed byte-by-byte for strpbrk()/strcspn().
     */
    private const CONTROL_CHAR_MASK = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0A\x0B\x0C\x0D\x0E\x0F"
        . "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    private Request $simpleRequest;
    private Request $headerHeavyRequest;
    private Request $multipartRequest;
    private Request $resetHeadersRequest;

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
        $this->resetHeadersRequest = new Request("GET /test HTTP/1.1\r\nHost: localhost\r\nX-Forwarded-For: 198.51.100.10\r\n\r\n");
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

    public function benchResetHeaders(): void
    {
        $this->resetHeadersRequest->resetHeaders();
    }

    /**
     * Micro-comparison for the header-value control-character filter (#630):
     * preg_match vs strpbrk vs strcspn over representative values.
     *
     * @ParamProviders("provideFilterValues")
     *
     * @param array{value: string} $params
     */
    public function benchFilterRegex(array $params): bool
    {
        return (bool) \preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $params['value']);
    }

    /**
     * @ParamProviders("provideFilterValues")
     *
     * @param array{value: string} $params
     */
    public function benchFilterStrpbrk(array $params): bool
    {
        return \strpbrk($params['value'], self::CONTROL_CHAR_MASK) !== false;
    }

    /**
     * @ParamProviders("provideFilterValues")
     *
     * @param array{value: string} $params
     */
    public function benchFilterStrcspn(array $params): bool
    {
        return \strcspn($params['value'], self::CONTROL_CHAR_MASK) < \strlen($params['value']);
    }

    /**
     * @return iterable<string, array{value: string}>
     */
    public static function provideFilterValues(): iterable
    {
        yield 'shortAccepted' => ['value' => 'X-Custom: short-value'];
        yield 'tabAccepted' => ['value' => "tab\twithin"];
        yield 'shortRejected' => ['value' => "bad\x01value"];
        yield 'longAccepted' => ['value' => str_repeat('Header-Value-0123456789-', 40)];
        yield 'longRejectedLate' => ['value' => str_repeat('a', 500) . "\x7F"];
        yield 'utf8Accepted' => ['value' => "Привет café — naïve 🚀 " . str_repeat('漢字テスト', 20)];
        yield 'utf8Rejected' => ['value' => "café \xE2\x9C\x93" . str_repeat('漢字', 20) . "\x00"];
    }
}
