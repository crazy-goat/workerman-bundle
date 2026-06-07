<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Protocol\Http\Response\StreamedBinaryFileResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final class StreamedBinaryFileResponseTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->fixtureDir = sys_get_temp_dir() . '/sfr_test_' . uniqid();
        mkdir($this->fixtureDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->fixtureDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->fixtureDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $fileinfo) {
                if ($fileinfo->isDir()) {
                    rmdir($fileinfo->getRealPath());
                } else {
                    unlink($fileinfo->getRealPath());
                }
            }
            rmdir($this->fixtureDir);
        }
    }

    public function testCanBeInstantiated(): void
    {
        $testFile = $this->createFixtureFile('test.txt', 'test content');
        $response = new StreamedBinaryFileResponse($testFile);

        $this->assertInstanceOf(StreamedBinaryFileResponse::class, $response);
    }

    public function testExtendsBinaryFileResponse(): void
    {
        $testFile = $this->createFixtureFile('test.txt', 'test content');
        $response = new StreamedBinaryFileResponse($testFile);

        $this->assertInstanceOf(BinaryFileResponse::class, $response);
    }

    public function testContentTypeIsAutomaticallyDetectedForTextFile(): void
    {
        $testFile = $this->createFixtureFile('test.txt', 'Hello, World!');
        $response = new StreamedBinaryFileResponse($testFile);

        $response->prepare($this->createRequest());

        $contentType = (string) $response->headers->get('Content-Type', '');
        $this->assertStringContainsString('text/plain', $contentType);
    }

    public function testContentTypeIsAutomaticallyDetectedForHtmlFile(): void
    {
        $testFile = $this->createFixtureFile('test.html', '<html><body>Hello</body></html>');
        $response = new StreamedBinaryFileResponse($testFile);

        $response->prepare($this->createRequest());

        $contentType = (string) $response->headers->get('Content-Type', '');
        $this->assertStringContainsString('text/html', $contentType);
    }

    public function testContentTypeIsOctetStreamForUnknownExtension(): void
    {
        $testFile = $this->createFixtureFile('test.bin', "\x00\x01\x02\x03");
        $response = new StreamedBinaryFileResponse($testFile);

        $response->prepare($this->createRequest());

        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
    }

    public function testContentLengthMatchesFileSize(): void
    {
        $content = 'This is a test file for StreamedBinaryFileResponse!' . \str_repeat('x', 1000);
        $expectedLength = \strlen($content);
        $testFile = $this->createFixtureFile('test.txt', $content);
        $response = new StreamedBinaryFileResponse($testFile);

        $response->prepare($this->createRequest());

        $this->assertSame((string) $expectedLength, $response->headers->get('Content-Length'));
    }

    public function testContentLengthForEmptyFile(): void
    {
        $testFile = $this->createFixtureFile('empty.txt', '');
        $response = new StreamedBinaryFileResponse($testFile);

        $response->prepare($this->createRequest());

        $this->assertSame('0', $response->headers->get('Content-Length'));
    }

    public function testContentDispositionCanBeSetToAttachment(): void
    {
        $testFile = $this->createFixtureFile('document.pdf', '%PDF-1.4 fake content');
        $response = new StreamedBinaryFileResponse($testFile);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT);

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertNotNull($disposition);
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('document.pdf', $disposition);
    }

    public function testContentDispositionCanBeSetToInline(): void
    {
        $testFile = $this->createFixtureFile('readme.txt', 'Read me!');
        $response = new StreamedBinaryFileResponse($testFile);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE);

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertNotNull($disposition);
        $this->assertStringContainsString('inline', $disposition);
    }

    public function testContentDispositionInConstructor(): void
    {
        $testFile = $this->createFixtureFile('download.zip', 'ZIP content');
        $response = new StreamedBinaryFileResponse(
            $testFile,
            200,
            [],
            true,
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
        );

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertNotNull($disposition);
        $this->assertStringContainsString('attachment', $disposition);
    }

    public function testGetFileReturnsExpectedFile(): void
    {
        $testFile = $this->createFixtureFile('data.json', '{"key": "value"}');
        $response = new StreamedBinaryFileResponse($testFile);

        $file = $response->getFile();

        $this->assertSame($testFile, $file->getPathname());
    }

    public function testSetChunkSizeAcceptsValidValues(): void
    {
        $testFile = $this->createFixtureFile('test.txt', 'content');
        $response = new StreamedBinaryFileResponse($testFile);

        $result = $response->setChunkSize(8192);

        $this->assertSame($response, $result);
    }

    public function testSetChunkSizeThrowsExceptionForInvalidValues(): void
    {
        $testFile = $this->createFixtureFile('test.txt', 'content');
        $response = new StreamedBinaryFileResponse($testFile);

        $this->expectException(\InvalidArgumentException::class);
        $response->setChunkSize(0);
    }

    public function testDeleteFileAfterSendFlagIsFalseByDefault(): void
    {
        $testFile = $this->createFixtureFile('test.txt', 'content');
        $response = new StreamedBinaryFileResponse($testFile);

        $this->assertFalse($this->getDeleteFileAfterSend($response));
    }

    public function testDeleteFileAfterSendCanBeEnabled(): void
    {
        $testFile = $this->createFixtureFile('test.txt', 'content');
        $response = new StreamedBinaryFileResponse($testFile);

        $response->deleteFileAfterSend(true);

        $this->assertTrue($this->getDeleteFileAfterSend($response));
    }

    public function testDeleteFileAfterSendDeletesFileAfterSendContent(): void
    {
        $testFile = $this->createFixtureFile('delete_me.txt', 'delete me');
        $response = new StreamedBinaryFileResponse($testFile);
        $response->deleteFileAfterSend(true);

        $this->assertFileExists($testFile);

        // Capture output from sendContent()
        ob_start();
        $response->sendContent();
        ob_end_clean();

        $this->assertFileDoesNotExist($testFile);
    }

    public function testFileIsNotDeletedWhenDeleteFileAfterSendIsFalse(): void
    {
        $testFile = $this->createFixtureFile('keep_me.txt', 'keep me');
        $response = new StreamedBinaryFileResponse($testFile);

        ob_start();
        $response->sendContent();
        ob_end_clean();

        $this->assertFileExists($testFile);

        unlink($testFile);
    }

    public function testSendContentOutputsFullFileContent(): void
    {
        $content = 'Hello from StreamedBinaryFileResponse!';
        $testFile = $this->createFixtureFile('test.txt', $content);
        $response = new StreamedBinaryFileResponse($testFile);

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $this->assertNotFalse($output);
        $this->assertSame($content, $output);
    }

    public function testSendContentOutputsCorrectLengthForLargeFile(): void
    {
        $content = \str_repeat("ABCDEFGHIJ\n", 2000); // ~22KB
        $testFile = $this->createFixtureFile('large.txt', $content);
        $response = new StreamedBinaryFileResponse($testFile);

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $this->assertNotFalse($output);
        $this->assertSame(\strlen($content), \strlen($output));
        $this->assertSame($content, $output);
    }

    public function testPrepareSetsContentLengthForLargeFile(): void
    {
        $content = \str_repeat("0123456789\n", 5000); // ~55KB
        $testFile = $this->createFixtureFile('big.txt', $content);
        $response = new StreamedBinaryFileResponse($testFile);

        $response->prepare($this->createRequest());

        $this->assertSame((string) \strlen($content), $response->headers->get('Content-Length'));
    }

    public function testStatusCanBeSet(): void
    {
        $testFile = $this->createFixtureFile('test.txt', 'content');
        $response = new StreamedBinaryFileResponse($testFile, 201);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testCustomHeadersCanBeSet(): void
    {
        $testFile = $this->createFixtureFile('test.txt', 'content');
        $response = new StreamedBinaryFileResponse($testFile, 200, ['X-Custom' => 'value']);

        $this->assertSame('value', $response->headers->get('X-Custom'));
    }

    public function testFileNotReadableThrowsException(): void
    {
        $testFile = $this->fixtureDir . '/unreadable.txt';
        file_put_contents($testFile, 'secret');
        chmod($testFile, 0000);

        $this->expectException(\Symfony\Component\HttpFoundation\File\Exception\FileException::class);

        try {
            new StreamedBinaryFileResponse($testFile);
        } finally {
            chmod($testFile, 0644);
            unlink($testFile);
        }
    }

    public function testAutoEtagHeaderIsSetWhenRequested(): void
    {
        $content = 'ETag test content';
        $testFile = $this->createFixtureFile('etag_test.txt', $content);
        $response = new StreamedBinaryFileResponse($testFile, 200, [], true, null, true);

        $response->prepare($this->createRequest());

        $this->assertNotNull($response->getEtag());
    }

    public function testAutoLastModifiedHeaderIsSetByDefault(): void
    {
        $testFile = $this->createFixtureFile('test.txt', 'content');
        $response = new StreamedBinaryFileResponse($testFile);

        $response->prepare($this->createRequest());

        $this->assertNotNull($response->getLastModified());
    }

    public function testAutoLastModifiedCanBeDisabled(): void
    {
        $testFile = $this->createFixtureFile('test.txt', 'content');
        $response = new StreamedBinaryFileResponse($testFile, 200, [], true, null, false, false);

        $response->prepare($this->createRequest());

        $this->assertNull($response->getLastModified());
    }

    public function testSetContentThrowsException(): void
    {
        $testFile = $this->createFixtureFile('test.txt', 'content');
        $response = new StreamedBinaryFileResponse($testFile);

        $this->expectException(\LogicException::class);
        $response->setContent('not allowed');
    }

    public function testGetContentReturnsFalse(): void
    {
        $testFile = $this->createFixtureFile('test.txt', 'content');
        $response = new StreamedBinaryFileResponse($testFile);

        $this->assertFalse($response->getContent());
    }

    public function testResponseIsPublicByDefault(): void
    {
        $testFile = $this->createFixtureFile('test.txt', 'public content');
        $response = new StreamedBinaryFileResponse($testFile);

        $this->assertTrue($response->headers->getCacheControlDirective('public'));
    }

    public function testResponseCanBePrivate(): void
    {
        $testFile = $this->createFixtureFile('test.txt', 'private content');
        $response = new StreamedBinaryFileResponse($testFile, 200, [], false);

        $this->assertNull($response->headers->getCacheControlDirective('public'));
    }

    private function createFixtureFile(string $filename, string $content): string
    {
        $path = $this->fixtureDir . '/' . $filename;
        file_put_contents($path, $content);

        return $path;
    }

    private function createRequest(): \Symfony\Component\HttpFoundation\Request
    {
        return \Symfony\Component\HttpFoundation\Request::create('http://localhost/test');
    }

    private function getDeleteFileAfterSend(BinaryFileResponse $response): bool
    {
        $property = new \ReflectionProperty(BinaryFileResponse::class, 'deleteFileAfterSend');
        $property->setAccessible(true);

        /** @var bool $value */
        $value = $property->getValue($response);

        return $value;
    }
}
