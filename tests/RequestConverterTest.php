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

        // Each file should be an UploadedFile instance
        foreach ($files as $file) {
            $this->assertInstanceOf(\Symfony\Component\HttpFoundation\File\UploadedFile::class, $file);
        }
    }

    public function testNestedFileArrayWithMultipleFiles(): void
    {
        $tmpFile1 = $this->createTempFile('content 1');
        $tmpFile2 = $this->createTempFile('content 2');
        $tmpFile3 = $this->createTempFile('content 3');

        $buffer = "POST /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $rawRequest = $this->createRequestWithFiles($buffer, [
            'documents' => [
                [
                    'name' => 'doc1.pdf',
                    'tmp_name' => $tmpFile1,
                    'type' => 'application/pdf',
                    'size' => 9,
                    'error' => \UPLOAD_ERR_OK,
                ],
                [
                    'name' => 'doc2.pdf',
                    'tmp_name' => $tmpFile2,
                    'type' => 'application/pdf',
                    'size' => 9,
                    'error' => \UPLOAD_ERR_OK,
                ],
                [
                    'name' => 'doc3.pdf',
                    'tmp_name' => $tmpFile3,
                    'type' => 'application/pdf',
                    'size' => 9,
                    'error' => \UPLOAD_ERR_OK,
                ],
            ],
        ]);

        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertTrue($symfonyRequest->files->has('documents'));
        $documents = $symfonyRequest->files->get('documents');
        $this->assertIsArray($documents);
        $this->assertCount(3, $documents);

        foreach ($documents as $index => $file) {
            $this->assertInstanceOf(\Symfony\Component\HttpFoundation\File\UploadedFile::class, $file);
            $this->assertSame('doc' . ($index + 1) . '.pdf', $file->getClientOriginalName());
        }
    }

    public function testDeeplyNestedAssociativeFileArray(): void
    {
        $tmpFile1 = $this->createTempFile('avatar content');
        $tmpFile2 = $this->createTempFile('resume content');

        $buffer = "POST /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $rawRequest = $this->createRequestWithFiles($buffer, [
            'user' => [
                'avatar' => [
                    'name' => 'avatar.png',
                    'tmp_name' => $tmpFile1,
                    'type' => 'image/png',
                    'size' => 14,
                    'error' => \UPLOAD_ERR_OK,
                ],
                'resume' => [
                    'name' => 'resume.pdf',
                    'tmp_name' => $tmpFile2,
                    'type' => 'application/pdf',
                    'size' => 16,
                    'error' => \UPLOAD_ERR_OK,
                ],
            ],
        ]);

        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertTrue($symfonyRequest->files->has('user'));
        $userFiles = $symfonyRequest->files->get('user');
        $this->assertIsArray($userFiles);
        $this->assertArrayHasKey('avatar', $userFiles);
        $this->assertArrayHasKey('resume', $userFiles);

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\File\UploadedFile::class, $userFiles['avatar']);
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\File\UploadedFile::class, $userFiles['resume']);
        $this->assertSame('avatar.png', $userFiles['avatar']->getClientOriginalName());
        $this->assertSame('resume.pdf', $userFiles['resume']->getClientOriginalName());
    }

    public function testMultipleFileInputsWithNumericKeys(): void
    {
        $tmpFile1 = $this->createTempFile('file 0 content');
        $tmpFile2 = $this->createTempFile('file 1 content');

        $buffer = "POST /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $rawRequest = $this->createRequestWithFiles($buffer, [
            'files' => [
                0 => [
                    'name' => 'image0.png',
                    'tmp_name' => $tmpFile1,
                    'type' => 'image/png',
                    'size' => 14,
                    'error' => \UPLOAD_ERR_OK,
                ],
                1 => [
                    'name' => 'image1.png',
                    'tmp_name' => $tmpFile2,
                    'type' => 'image/png',
                    'size' => 14,
                    'error' => \UPLOAD_ERR_OK,
                ],
            ],
        ]);

        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertTrue($symfonyRequest->files->has('files'));
        $files = $symfonyRequest->files->get('files');
        $this->assertIsArray($files);
        $this->assertCount(2, $files);

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\File\UploadedFile::class, $files[0]);
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\File\UploadedFile::class, $files[1]);
        $this->assertSame('image0.png', $files[0]->getClientOriginalName());
        $this->assertSame('image1.png', $files[1]->getClientOriginalName());
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

    public function testNonArrayFileEntryIsNotSilentlyDropped(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expected array, got string');

        $buffer = "POST /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $rawRequest = $this->createRequestWithFiles($buffer, [
            'invalid_file' => 'not an array',
        ]);

        RequestConverter::toSymfonyRequest($rawRequest);
    }

    public function testMixedFileArrayWithNonArrayLeaf(): void
    {
        $tmpFile = $this->createTempFile('avatar content');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/expected array, got string/');

        $buffer = "POST /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $rawRequest = $this->createRequestWithFiles($buffer, [
            'avatar' => [
                'name' => 'avatar.png',
                'tmp_name' => $tmpFile,
                'type' => 'image/png',
                'size' => 14,
                'error' => \UPLOAD_ERR_OK,
            ],
            'logo' => 'not an array',
        ]);

        RequestConverter::toSymfonyRequest($rawRequest);
    }

    public function testDeeplyNestedNonArrayFileEntry(): void
    {
        $tmpFile = $this->createTempFile('avatar content');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/expected array, got string/');

        $buffer = "POST /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $rawRequest = $this->createRequestWithFiles($buffer, [
            'user' => [
                'avatar' => [
                    'name' => 'avatar.png',
                    'tmp_name' => $tmpFile,
                    'type' => 'image/png',
                    'size' => 14,
                    'error' => \UPLOAD_ERR_OK,
                ],
                'logo' => 'string',
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

    public function testHttpsDetectedFromSslTransport(): void
    {
        $buffer = "GET /test HTTP/1.1\r\nHost: example.com\r\n\r\n";
        $rawRequest = new Request($buffer);
        $rawRequest->connection = $this->createMockConnection(443, 'ssl');

        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame(443, $symfonyRequest->server->get('SERVER_PORT'));
        $this->assertSame('on', $symfonyRequest->server->get('HTTPS'));
        $this->assertSame('https', $symfonyRequest->getScheme());
    }

    public function testHttpDetectedFromTcpTransport(): void
    {
        $buffer = "GET /test HTTP/1.1\r\nHost: example.com\r\n\r\n";
        $rawRequest = new Request($buffer);
        $rawRequest->connection = $this->createMockConnection(80, 'tcp');

        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame(80, $symfonyRequest->server->get('SERVER_PORT'));
        $this->assertNull($symfonyRequest->server->get('HTTPS'));
        $this->assertSame('http', $symfonyRequest->getScheme());
    }

    public function testHttpsDetectedFromSslTransportOnAnyPort(): void
    {
        $buffer = "GET /test HTTP/1.1\r\nHost: example.com\r\n\r\n";
        $rawRequest = new Request($buffer);
        $rawRequest->connection = $this->createMockConnection(8443, 'ssl');

        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame(8443, $symfonyRequest->server->get('SERVER_PORT'));
        $this->assertSame('on', $symfonyRequest->server->get('HTTPS'));
        $this->assertSame('https', $symfonyRequest->getScheme());
    }

    public function testGetSchemeAndHttpHostOmitsPort443ForHttps(): void
    {
        $buffer = "GET /test HTTP/1.1\r\nHost: example.com\r\n\r\n";
        $rawRequest = new Request($buffer);
        $rawRequest->connection = $this->createMockConnection(443, 'ssl');

        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame('https://example.com', $symfonyRequest->getSchemeAndHttpHost());
    }

    public function testXForwardedProtoIgnoredOnTcpTransport(): void
    {
        $buffer = "GET /test HTTP/1.1\r\nHost: localhost\r\nX-Forwarded-Proto: https\r\n\r\n";
        $rawRequest = new Request($buffer);
        $rawRequest->connection = $this->createMockConnection(80, 'tcp');

        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertNull($symfonyRequest->server->get('HTTPS'));
        $this->assertFalse($symfonyRequest->isSecure());
        $this->assertSame('http', $symfonyRequest->getScheme());
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

    public function testMultipartRequestReturnsEmptyContent(): void
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
        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame('', $symfonyRequest->getContent());
        $this->assertTrue($symfonyRequest->files->has('test_file'));
    }

    public function testJsonRequestPreservesContent(): void
    {
        $buffer = "POST /test HTTP/1.1\r\n";
        $buffer .= "Host: localhost\r\n";
        $buffer .= "Content-Type: application/json\r\n";
        $buffer .= "Content-Length: 15\r\n";
        $buffer .= "\r\n";
        $buffer .= '{"key":"value"}';

        $rawRequest = new Request($buffer);
        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame('{"key":"value"}', $symfonyRequest->getContent());
    }

    public function testFormUrlEncodedPreservesContent(): void
    {
        $buffer = "POST /test HTTP/1.1\r\n";
        $buffer .= "Host: localhost\r\n";
        $buffer .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $buffer .= "Content-Length: 9\r\n";
        $buffer .= "\r\n";
        $buffer .= 'key=value';

        $rawRequest = new Request($buffer);
        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame('key=value', $symfonyRequest->getContent());
    }

    /** @dataProvider provideControlCharacters */
    public function testUriWithControlCharacterIsRejected(string $controlChar): void
    {
        $buffer = "GET /test{$controlChar}something HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $rawRequest = new Request($buffer);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Request URI contains control characters');
        RequestConverter::toSymfonyRequest($rawRequest);
    }

    /** @return iterable<string, array{string}> */
    public static function provideControlCharacters(): iterable
    {
        yield 'NUL byte' => ["\x00"];
        yield 'carriage return' => ["\r"];
        yield 'line feed' => ["\n"];
        yield 'vertical tab' => ["\v"];
        yield 'form feed' => ["\f"];
        yield 'DEL byte' => ["\x7F"];
    }

    public function testMethodWithControlCharacterIsRejected(): void
    {
        $buffer = "G\x00ET /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $rawRequest = new Request($buffer);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP method contains invalid characters');
        RequestConverter::toSymfonyRequest($rawRequest);
    }

    public function testMethodExceedsMaxLengthIsRejected(): void
    {
        $longMethod = str_repeat('A', 33);
        $buffer = "{$longMethod} /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $rawRequest = new Request($buffer);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP method exceeds maximum length');
        RequestConverter::toSymfonyRequest($rawRequest);
    }

    public function testStandardMethodsAndUrisStillWork(): void
    {
        $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'CONNECT', 'TRACE'];

        foreach ($methods as $method) {
            $buffer = "{$method} /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
            $rawRequest = new Request($buffer);

            $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);
            $this->assertSame("/test", $symfonyRequest->server->get('REQUEST_URI'));
            $this->assertSame($method, $symfonyRequest->server->get('REQUEST_METHOD'));
        }
    }

    public function testMethodWithLowercaseIsRejected(): void
    {
        $buffer = "get /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $rawRequest = new Request($buffer);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP method contains invalid characters');
        RequestConverter::toSymfonyRequest($rawRequest);
    }

    public function testMultipleCookieHeadersJoinedWithSemicolon(): void
    {
        $buffer = "GET /test HTTP/1.1\r\n";
        $buffer .= "Host: localhost\r\n";
        $buffer .= "Cookie: session=abc123\r\n";
        $buffer .= "Cookie: token=xyz789\r\n";
        $buffer .= "\r\n";

        $rawRequest = new Request($buffer);
        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame('session=abc123; token=xyz789', $symfonyRequest->server->get('HTTP_COOKIE'));
        $this->assertSame('abc123', $symfonyRequest->cookies->get('session'));
        $this->assertSame('xyz789', $symfonyRequest->cookies->get('token'));
    }

    public function testMultipleHostHeadersKeepsFirstOnly(): void
    {
        $buffer = "GET /test HTTP/1.1\r\n";
        $buffer .= "Host: example.com\r\n";
        $buffer .= "Host: attacker.com\r\n";
        $buffer .= "\r\n";

        $rawRequest = new Request($buffer);
        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame('example.com', $symfonyRequest->server->get('HTTP_HOST'));
        $this->assertStringNotContainsString('attacker.com', $symfonyRequest->server->get('HTTP_HOST'));
    }

    public function testMultipleContentLengthHeadersKeepsFirstOnly(): void
    {
        $buffer = "POST /test HTTP/1.1\r\n";
        $buffer .= "Host: localhost\r\n";
        $buffer .= "Content-Length: 100\r\n";
        $buffer .= "Content-Length: 9999\r\n";
        $buffer .= "\r\n";

        $rawRequest = new Request($buffer);
        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame('100', $symfonyRequest->server->get('CONTENT_LENGTH'));
        $this->assertNull($symfonyRequest->server->get('HTTP_CONTENT_LENGTH'));
    }

    public function testMultipleAuthorizationHeadersKeepsFirstOnly(): void
    {
        $buffer = "GET /test HTTP/1.1\r\n";
        $buffer .= "Host: localhost\r\n";
        $buffer .= "Authorization: Bearer valid-token\r\n";
        $buffer .= "Authorization: Bearer attacker-token\r\n";
        $buffer .= "\r\n";

        $rawRequest = new Request($buffer);
        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame('Bearer valid-token', $symfonyRequest->server->get('HTTP_AUTHORIZATION'));
        $this->assertStringNotContainsString('attacker-token', $symfonyRequest->server->get('HTTP_AUTHORIZATION'));
    }

    public function testNonSensitiveDuplicateHeadersJoinedWithComma(): void
    {
        $buffer = "GET /test HTTP/1.1\r\n";
        $buffer .= "Host: localhost\r\n";
        $buffer .= "Accept: text/plain\r\n";
        $buffer .= "Accept: application/json\r\n";
        $buffer .= "\r\n";

        $rawRequest = new Request($buffer);
        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame('text/plain, application/json', $symfonyRequest->server->get('HTTP_ACCEPT'));
    }

    public function testSingleValueHeadersStillWork(): void
    {
        $buffer = "GET /test HTTP/1.1\r\n";
        $buffer .= "Host: example.com\r\n";
        $buffer .= "Accept: application/json\r\n";
        $buffer .= "Authorization: Basic dXNlcjpwYXNz\r\n";
        $buffer .= "Content-Type: application/json\r\n";
        $buffer .= "Content-Length: 123\r\n";
        $buffer .= "X-Custom: custom-value\r\n";
        $buffer .= "\r\n";

        $rawRequest = new Request($buffer);
        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame('example.com', $symfonyRequest->server->get('HTTP_HOST'));
        $this->assertSame('application/json', $symfonyRequest->server->get('HTTP_ACCEPT'));
        $this->assertSame('Basic dXNlcjpwYXNz', $symfonyRequest->server->get('HTTP_AUTHORIZATION'));
        $this->assertSame('application/json', $symfonyRequest->server->get('CONTENT_TYPE'));
        $this->assertSame('123', $symfonyRequest->server->get('CONTENT_LENGTH'));
        $this->assertSame('custom-value', $symfonyRequest->server->get('HTTP_X_CUSTOM'));
    }

    public function testMultipleTransferEncodingHeadersKeepsFirstOnly(): void
    {
        $buffer = "GET /test HTTP/1.1\r\n";
        $buffer .= "Host: localhost\r\n";
        $buffer .= "Transfer-Encoding: chunked\r\n";
        $buffer .= "Transfer-Encoding: x\r\n";
        $buffer .= "\r\n";

        $rawRequest = new Request($buffer);
        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame('chunked', $symfonyRequest->server->get('HTTP_TRANSFER_ENCODING'));
        $this->assertStringNotContainsString('x', $symfonyRequest->server->get('HTTP_TRANSFER_ENCODING'));
    }

    public function testMixedSingleAndDuplicateHeaders(): void
    {
        $buffer = "GET /test HTTP/1.1\r\n";
        $buffer .= "Host: example.com\r\n";
        $buffer .= "Accept: text/plain\r\n";
        $buffer .= "Accept: application/json\r\n";
        $buffer .= "X-Custom: single-value\r\n";
        $buffer .= "X-Another: value-a\r\n";
        $buffer .= "X-Another: value-b\r\n";
        $buffer .= "X-Another: value-c\r\n";
        $buffer .= "\r\n";

        $rawRequest = new Request($buffer);
        $symfonyRequest = RequestConverter::toSymfonyRequest($rawRequest);

        $this->assertSame('example.com', $symfonyRequest->server->get('HTTP_HOST'));
        $this->assertSame('text/plain, application/json', $symfonyRequest->server->get('HTTP_ACCEPT'));
        $this->assertSame('single-value', $symfonyRequest->server->get('HTTP_X_CUSTOM'));
        $this->assertSame('value-a, value-b, value-c', $symfonyRequest->server->get('HTTP_X_ANOTHER'));
    }

    public function testHeaderWithControlCharacterIsRejected(): void
    {
        $buffer = "GET /test HTTP/1.1\r\n";
        $buffer .= "Host: localhost\r\n";
        $buffer .= "X-Injected: value\x00malicious\r\n";
        $buffer .= "\r\n";

        $rawRequest = new Request($buffer);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Header "x-injected" contains control characters');
        RequestConverter::toSymfonyRequest($rawRequest);
    }

    private function createMockConnection(int $localPort, string $transport = 'tcp'): \Workerman\Connection\TcpConnection
    {
        return new class ($localPort, $transport) extends \Workerman\Connection\TcpConnection {
            public function __construct(private readonly int $port, string $transport)
            {
                $this->remoteAddress = '192.168.1.1:12345';
                $this->transport = $transport;
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

    public function testDetectFormDataWithMultipartContentType(): void
    {
        $result = $this->callPrivateStaticMethod('detectFormData', ['multipart/form-data; boundary=xyz']);

        $this->assertTrue($result['isMultipart']);
        $this->assertTrue($result['isFormData']);
    }

    public function testDetectFormDataWithFormUrlEncoded(): void
    {
        $result = $this->callPrivateStaticMethod('detectFormData', ['application/x-www-form-urlencoded']);

        $this->assertFalse($result['isMultipart']);
        $this->assertTrue($result['isFormData']);
    }

    public function testDetectFormDataWithJsonContentType(): void
    {
        $result = $this->callPrivateStaticMethod('detectFormData', ['application/json']);

        $this->assertFalse($result['isMultipart']);
        $this->assertFalse($result['isFormData']);
    }

    public function testDetectFormDataWithEmptyContentType(): void
    {
        $result = $this->callPrivateStaticMethod('detectFormData', ['']);

        $this->assertFalse($result['isMultipart']);
        $this->assertFalse($result['isFormData']);
    }

    public function testDetectFormDataIsCaseInsensitive(): void
    {
        $result = $this->callPrivateStaticMethod('detectFormData', ['MULTIPART/FORM-DATA; boundary=test']);

        $this->assertTrue($result['isMultipart']);
        $this->assertTrue($result['isFormData']);
    }

    public function testBuildServerBagWithConnection(): void
    {
        $buffer = "GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $rawRequest = new Request($buffer);
        $rawRequest->connection = $this->createMockConnection(8080);

        $server = $this->callPrivateStaticMethod('buildServerBag', [
            $rawRequest,
            '/test',
            'GET',
            false,
            1234567890.123,
        ]);

        $this->assertSame('/test', $server['REQUEST_URI']);
        $this->assertSame('GET', $server['REQUEST_METHOD']);
        $this->assertSame('HTTP/1.1', $server['SERVER_PROTOCOL']);
        $this->assertSame('192.168.1.1', $server['REMOTE_ADDR']);
        $this->assertSame(12345, $server['REMOTE_PORT']);
        $this->assertSame(8080, $server['SERVER_PORT']);
        $this->assertSame('0.0.0.0', $server['SERVER_NAME']);
        $this->assertSame('', $server['QUERY_STRING']);
        $this->assertSame(1234567890, $server['REQUEST_TIME']);
        $this->assertSame(1234567890.123, $server['REQUEST_TIME_FLOAT']);
        $this->assertArrayNotHasKey('HTTPS', $server);
    }

    public function testBuildServerBagWithoutConnection(): void
    {
        $buffer = "GET /test?foo=bar HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $rawRequest = new Request($buffer);
        $rawRequest->connection = null;

        $server = $this->callPrivateStaticMethod('buildServerBag', [
            $rawRequest,
            '/test?foo=bar',
            'GET',
            false,
            1234567890.456,
        ]);

        $this->assertSame('127.0.0.1', $server['REMOTE_ADDR']);
        $this->assertSame(0, $server['REMOTE_PORT']);
        $this->assertSame(80, $server['SERVER_PORT']);
        $this->assertSame('localhost', $server['SERVER_NAME']);
        $this->assertSame('foo=bar', $server['QUERY_STRING']);
        $this->assertArrayNotHasKey('HTTPS', $server);
    }

    public function testBuildServerBagWithHttps(): void
    {
        $buffer = "GET /secure HTTP/1.1\r\nHost: example.com\r\n\r\n";
        $rawRequest = new Request($buffer);
        $rawRequest->connection = $this->createMockConnection(443, 'ssl');

        $server = $this->callPrivateStaticMethod('buildServerBag', [
            $rawRequest,
            '/secure',
            'GET',
            true,
            1234567890.789,
        ]);

        $this->assertSame('on', $server['HTTPS']);
        $this->assertSame(443, $server['SERVER_PORT']);
    }

    /**
     * Call a private static method via reflection for unit testing.
     *
     * @param array<int, mixed> $args
     */
    private function callPrivateStaticMethod(string $methodName, array $args): mixed
    {
        $reflection = new \ReflectionMethod(RequestConverter::class, $methodName);

        return $reflection->invokeArgs(null, $args);
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
        if (file_put_contents($tmpFile, $content) === false) {
            throw new \RuntimeException('Failed to write to temp file');
        }
        $this->tempFiles[] = $tmpFile;

        return $tmpFile;
    }

    /**
     * @param array<string, mixed> $files
     */
    private function createRequestWithFiles(string $buffer, array $files): Request
    {
        $rawRequest = new Request($buffer);

        // Workerman's Request does not expose a public API for file injection.
        // Reflection is required to simulate malformed file uploads for testing.
        $reflection = new \ReflectionClass($rawRequest);
        $dataProperty = $reflection->getProperty('data');
        $dataProperty->setValue($rawRequest, ['files' => $files]);

        return $rawRequest;
    }
}
