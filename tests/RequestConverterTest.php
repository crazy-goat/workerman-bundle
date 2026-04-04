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

        // Create a temp file so Workerman doesn't clear our files array
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmpFile, 'test content');

        $buffer = "POST /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $rawRequest = new Request($buffer);

        // Manually inject malformed file data (missing required fields) but with valid tmp_name
        // so Workerman's file() method doesn't clear it
        $reflection = new \ReflectionClass($rawRequest);
        $dataProperty = $reflection->getProperty('data');
        $dataProperty->setValue($rawRequest, [
            'files' => [
                'malformed_file' => [
                    'name' => 'test.txt',
                    'tmp_name' => $tmpFile,
                    // Missing 'type', 'size', 'error' - should trigger validation error
                ],
            ],
        ]);

        try {
            // This should throw InvalidArgumentException
            RequestConverter::toSymfonyRequest($rawRequest);
            // If we get here, delete the file
            unlink($tmpFile);
        } catch (InvalidArgumentException $e) {
            // Exception thrown as expected, clean up
            unlink($tmpFile);
            throw $e;
        }
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

        // Authorization should be parsed into PHP_AUTH_* (Symfony parses this from HTTP_AUTHORIZATION)
        $this->assertSame('user', $symfonyRequest->getUser());
        $this->assertSame('pass', $symfonyRequest->getPassword());

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
}
