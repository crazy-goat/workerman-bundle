<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\DTO\RequestConverter;
use CrazyGoat\WorkermanBundle\Http\Request;
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
