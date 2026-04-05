<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\DTO\RequestConverter;
use CrazyGoat\WorkermanBundle\Http\Request;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CrazyGoat\WorkermanBundle\DTO\RequestConverter
 */
final class RequestConverterTest extends TestCase
{
    /** @var string[] */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];

        parent::tearDown();
    }
    public function testValidFileStructureIsAccepted(): void
    {
        $buffer = $this->createMultipartRequest(
            boundary: 'TestBoundary',
            fields: [
                [
                    'name' => 'test_file',
                    'filename' => 'test.txt',
                    'content' => 'test content',
                ],
            ],
        );

        $rawRequest = new Request($buffer);

        // Should not throw
        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\Request::class, $symfonyRequest);
        $this->assertTrue($symfonyRequest->files->has('test_file'));
    }

    public function testNestedFileArrayValidation(): void
    {
        $buffer = $this->createMultipartRequest(
            boundary: 'TestBoundaryNested',
            fields: [
                [
                    'name' => 'files[]',
                    'filename' => 'file1.txt',
                    'content' => 'content 1',
                ],
                [
                    'name' => 'files[]',
                    'filename' => 'file2.txt',
                    'content' => 'content 2',
                ],
            ],
        );

        $rawRequest = new Request($buffer);

        // Should not throw
        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\Request::class, $symfonyRequest);
        $this->assertTrue($symfonyRequest->files->has('files'));

        $files = $symfonyRequest->files->get('files');
        $this->assertIsArray($files);
        $this->assertCount(2, $files);
    }

    public function testNestedAssociativeFileArrayValidation(): void
    {
        $buffer = $this->createMultipartRequest(
            boundary: 'TestBoundaryAssoc',
            fields: [
                [
                    'name' => 'user[avatar]',
                    'filename' => 'avatar.png',
                    'content' => 'fake image',
                ],
                [
                    'name' => 'user[resume]',
                    'filename' => 'resume.pdf',
                    'content' => 'fake pdf',
                ],
            ],
        );

        $rawRequest = new Request($buffer);

        // Should not throw
        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\Request::class, $symfonyRequest);
        $this->assertTrue($symfonyRequest->files->has('user'));

        $userFiles = $symfonyRequest->files->get('user');
        $this->assertIsArray($userFiles);
        $this->assertArrayHasKey('avatar', $userFiles);
        $this->assertArrayHasKey('resume', $userFiles);
    }

    public function testEmptyFilesArrayIsAccepted(): void
    {
        $buffer = "GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $rawRequest = new Request($buffer);

        // Should not throw
        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\Request::class, $symfonyRequest);
        $this->assertCount(0, $symfonyRequest->files->all());
    }

    public function testMalformedFileDataThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing required field');

        $tmpFile = $this->createTempFile('test content');

        $buffer = "POST /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $rawRequest = $this->createRequestWithFiles($buffer, [
            'malformed_file' => [
                'name' => 'test.txt',
                'tmp_name' => $tmpFile,
            ],
        ]);

        RequestConverter::toSymfonyRequest($rawRequest);
    }

    public function testHeadersAreAvailableInServerBag(): void
    {
        $buffer = "GET /test HTTP/1.1\r\n";
        $buffer .= "Host: example.com\r\n";
        $buffer .= "Accept: application/json\r\n";
        $buffer .= "Authorization: Basic " . base64_encode('user:pass') . "\r\n";
        $buffer .= "Content-Type: application/json\r\n";
        $buffer .= "Content-Length: 123\r\n";
        $buffer .= "\r\n";

        $rawRequest = new Request($buffer);
        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        // Headers should be in server bag with HTTP_ prefix
        $this->assertSame('example.com', $symfonyRequest->server->get('HTTP_HOST'));
        $this->assertSame('application/json', $symfonyRequest->server->get('HTTP_ACCEPT'));

        // Authorization should be parsed into PHP_AUTH_USER/PHP_AUTH_PW via ServerBag
        // getUser()/getPassword() read these from headers, which are populated by ServerBag::getHeaders()
        $this->assertSame('user', $symfonyRequest->getUser());
        $this->assertSame('pass', $symfonyRequest->getPassword());

        // Verify PHP_AUTH_* are in headers (populated by ServerBag from HTTP_AUTHORIZATION)
        $this->assertSame('user', $symfonyRequest->headers->get('PHP_AUTH_USER'));
        $this->assertSame('pass', $symfonyRequest->headers->get('PHP_AUTH_PW'));

        // Content-Type and Content-Length should NOT have HTTP_ prefix (CGI convention)
        $this->assertSame('application/json', $symfonyRequest->server->get('CONTENT_TYPE'));
        $this->assertSame('123', $symfonyRequest->server->get('CONTENT_LENGTH'));

        // HTTP_CONTENT_TYPE should NOT exist (moved to CONTENT_TYPE)
        $this->assertNull($symfonyRequest->server->get('HTTP_CONTENT_TYPE'));
        $this->assertNull($symfonyRequest->server->get('HTTP_CONTENT_LENGTH'));
    }

    public function testServerProtocolHasHttpPrefix(): void
    {
        $buffer = "GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $rawRequest = new Request($buffer);
        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame('HTTP/1.1', $symfonyRequest->server->get('SERVER_PROTOCOL'));
    }

    public function testRemoteAddrDefaultsToLocalhostWhenNoConnection(): void
    {
        $buffer = "GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $rawRequest = new Request($buffer);
        // Ensure no connection is attached (unit test scenario)
        $rawRequest->connection = null;

        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame('127.0.0.1', $symfonyRequest->server->get('REMOTE_ADDR'));
    }

    public function testRemotePortDefaultsToZeroWhenNoConnection(): void
    {
        $buffer = "GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $rawRequest = new Request($buffer);
        $rawRequest->connection = null;

        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame(0, $symfonyRequest->server->get('REMOTE_PORT'));
    }

    public function testGetClientIpReturnsRemoteAddr(): void
    {
        $buffer = "GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $rawRequest = new Request($buffer);

        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame('127.0.0.1', $symfonyRequest->getClientIp());
    }

    public function testIsFromTrustedProxyWorksWhenConfigured(): void
    {
        $buffer = "GET /test HTTP/1.1\r\nHost: localhost\r\nX-Forwarded-For: 192.168.1.100\r\n\r\n";
        $rawRequest = new Request($buffer);

        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $trustedHeaders = \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_FOR
            | \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PROTO
            | \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_HOST
            | \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PORT;
        \Symfony\Component\HttpFoundation\Request::setTrustedProxies(['127.0.0.1'], $trustedHeaders);
        $this->assertTrue($symfonyRequest->isFromTrustedProxy());
        \Symfony\Component\HttpFoundation\Request::setTrustedProxies([], $trustedHeaders);
    }

    public function testRemoteAddrWithMockConnection(): void
    {
        $buffer = "GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $rawRequest = new Request($buffer);

        $mockConnection = new class extends \Workerman\Connection\TcpConnection {
            public function __construct()
            {
                $this->remoteAddress = '192.168.1.100:12345';
            }
        };
        $rawRequest->connection = $mockConnection;

        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame('192.168.1.100', $symfonyRequest->server->get('REMOTE_ADDR'));
        $this->assertSame(12345, $symfonyRequest->server->get('REMOTE_PORT'));
    }

    public function testServerPortFromConnection(): void
    {
        $buffer = "GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $rawRequest = new Request($buffer);
        $rawRequest->connection = $this->createMockConnection(8080);

        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame(8080, $symfonyRequest->server->get('SERVER_PORT'));
    }

    public function testServerPortDefaultsTo80WhenNoConnection(): void
    {
        $buffer = "GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $rawRequest = new Request($buffer);
        $rawRequest->connection = null;

        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame(80, $symfonyRequest->server->get('SERVER_PORT'));
    }

    public function testQueryStringFromRequest(): void
    {
        $buffer = "GET /test?foo=bar&baz=qux HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $rawRequest = new Request($buffer);

        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame('foo=bar&baz=qux', $symfonyRequest->server->get('QUERY_STRING'));
        $this->assertSame('baz=qux&foo=bar', $symfonyRequest->getQueryString());
    }

    public function testQueryStringEmptyForNoQueryParams(): void
    {
        $buffer = "GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $rawRequest = new Request($buffer);

        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame('', $symfonyRequest->server->get('QUERY_STRING'));
    }

    public function testGetPortReturnsServerPortWhenNoHostHeader(): void
    {
        $buffer = "GET /test HTTP/1.1\r\n\r\n";
        $rawRequest = new Request($buffer);
        $rawRequest->connection = $this->createMockConnection(8443);

        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame(8443, $symfonyRequest->getPort());
    }

    public function testGetPortReturnsPortFromHostHeaderWhenPresent(): void
    {
        $buffer = "GET /test HTTP/1.1\r\nHost: localhost:8080\r\n\r\n";
        $rawRequest = new Request($buffer);
        $rawRequest->connection = $this->createMockConnection(8080);

        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame(8080, $symfonyRequest->getPort());
    }

    public function testServerPortDefaultsTo443WhenNoConnectionButHttpsForwarded(): void
    {
        $buffer = "GET /test HTTP/1.1\r\nHost: localhost\r\nX-Forwarded-Proto: https\r\n\r\n";
        $rawRequest = new Request($buffer);
        $rawRequest->connection = null;

        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame(443, $symfonyRequest->server->get('SERVER_PORT'));
        $this->assertSame('on', $symfonyRequest->server->get('HTTPS'));
    }

    public function testHttpsDetectedFromPort443(): void
    {
        $buffer = "GET /test HTTP/1.1\r\nHost: example.com\r\n\r\n";
        $rawRequest = new Request($buffer);
        $rawRequest->connection = $this->createMockConnection(443);

        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame(443, $symfonyRequest->server->get('SERVER_PORT'));
        $this->assertSame('on', $symfonyRequest->server->get('HTTPS'));
        $this->assertSame('https', $symfonyRequest->getScheme());
    }

    public function testGetSchemeAndHttpHostOmitsPort443ForHttps(): void
    {
        $buffer = "GET /test HTTP/1.1\r\nHost: example.com\r\n\r\n";
        $rawRequest = new Request($buffer);
        $rawRequest->connection = $this->createMockConnection(443);

        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame('https://example.com', $symfonyRequest->getSchemeAndHttpHost());
    }

    public function testRequestTimeAndRequestTimeFloatAreSet(): void
    {
        $buffer = "GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $rawRequest = new Request($buffer);

        $before = microtime(true);
        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);
        $after = microtime(true);

        $requestTime = $symfonyRequest->server->get('REQUEST_TIME');
        $requestTimeFloat = $symfonyRequest->server->get('REQUEST_TIME_FLOAT');

        $this->assertIsInt($requestTime);
        $this->assertIsFloat($requestTimeFloat);
        $this->assertEqualsWithDelta($before, $requestTimeFloat, 0.1);
        $this->assertEqualsWithDelta($after, $requestTimeFloat, 0.1);
        $this->assertSame($requestTime, (int) $requestTimeFloat);
    }

    private function createMockConnection(int $localPort): \Workerman\Connection\TcpConnection
    {
        return new class ($localPort) extends \Workerman\Connection\TcpConnection {
            public function __construct(private readonly int $port)
            {
                $this->remoteAddress = '192.168.1.1:12345';
            }

            public function getLocalPort(): int
            {
                return $this->port;
            }

            public function getLocalIp(): string
            {
                return '0.0.0.0';
            }
        };
    }

    /**
     * @param array<int, array{name: string, filename: string, content: string}> $fields
     */
    private function createMultipartRequest(string $boundary, array $fields): string
    {
        $body = '';

        foreach ($fields as $field) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$field['name']}\"; filename=\"{$field['filename']}\"\r\n";
            $body .= "Content-Type: text/plain\r\n\r\n";
            $body .= $field['content'] . "\r\n";
        }

        $body .= "--{$boundary}--\r\n";

        $buffer = "POST /test HTTP/1.1\r\n";
        $buffer .= "Host: localhost\r\n";
        $buffer .= "Content-Type: multipart/form-data; boundary={$boundary}\r\n";
        $buffer .= 'Content-Length: ' . strlen($body) . "\r\n";
        $buffer .= "\r\n";

        return $buffer . $body;
    }

    private function createTempFile(string $content = 'test'): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        if ($tmpFile === false) {
            throw new \RuntimeException('Failed to create temp file');
        }
        file_put_contents($tmpFile, $content);
        $this->tempFiles[] = $tmpFile;

        return $tmpFile;
    }

    /**
     * @param array<string, array<string, mixed>> $files
     */
    private function createRequestWithFiles(string $buffer, array $files): Request
    {
        $rawRequest = new Request($buffer);

        $reflection = new \ReflectionClass($rawRequest);
        $dataProperty = $reflection->getProperty('data');
        $dataProperty->setValue($rawRequest, ['files' => $files]);

        return $rawRequest;
    }
}
