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

    public function testMissingRequiredFieldThrowsException(): void
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

    public function testNonArrayFileDataThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expected array, got');

        // Create a temp file so Workerman doesn't clear our files array
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmpFile, 'test content');

        $buffer = "POST /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $rawRequest = new Request($buffer);

        // Inject non-array file data but with a sibling that has valid tmp_name
        // to prevent Workerman from clearing the entire files array
        $reflection = new \ReflectionClass($rawRequest);
        $dataProperty = $reflection->getProperty('data');
        $dataProperty->setValue($rawRequest, [
            'files' => [
                'valid_file' => [
                    'name' => 'valid.txt',
                    'tmp_name' => $tmpFile,
                    'type' => 'text/plain',
                    'size' => 12,
                    'error' => 0,
                ],
                'invalid_file' => 'not an array',
            ],
        ]);

        try {
            RequestConverter::toSymfonyRequest($rawRequest);
            unlink($tmpFile);
        } catch (InvalidArgumentException $e) {
            unlink($tmpFile);
            throw $e;
        }
    }

    public function testUnrecognizedNestedStructureThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unrecognized structure');

        // Create a temp file so Workerman doesn't clear our files array
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmpFile, 'test content');

        $buffer = "POST /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $rawRequest = new Request($buffer);

        // Inject nested file data with unrecognized structure but with valid tmp_name
        // at the top level to prevent Workerman from clearing the files array
        $reflection = new \ReflectionClass($rawRequest);
        $dataProperty = $reflection->getProperty('data');
        $dataProperty->setValue($rawRequest, [
            'files' => [
                'valid_file' => [
                    'name' => 'valid.txt',
                    'tmp_name' => $tmpFile,
                    'type' => 'text/plain',
                    'size' => 12,
                    'error' => 0,
                ],
                'nested' => [
                    'unrecognized' => [
                        'foo' => 'bar',
                        // Missing file keys (name, tmp_name)
                    ],
                ],
            ],
        ]);

        try {
            RequestConverter::toSymfonyRequest($rawRequest);
            unlink($tmpFile);
        } catch (InvalidArgumentException $e) {
            unlink($tmpFile);
            throw $e;
        }
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
