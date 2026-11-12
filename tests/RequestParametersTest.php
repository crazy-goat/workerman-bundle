<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Test\App\TestPorts;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Psr7\MultipartStream;

use function PHPUnit\Framework\assertIsArray;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RequestParametersTest extends KernelTestCase
{
    public function testHeaders(): void
    {
        $response = $this->createResponse('GET', [
            'headers' => [
                'test-header-1' => '9hnwk8xuxzt8qdc4wcsrr26uqqsuz8',
            ],
        ]);

        assertIsArray($response['headers']);
        assertIsArray($response['headers']['test-header-1']);

        $this->assertSame('9hnwk8xuxzt8qdc4wcsrr26uqqsuz8', $response['headers']['test-header-1'][0] ?? null);
    }

    public function testGetParameters(): void
    {
        $response = $this->createResponse('GET', [
            'query' => [
                'test-query-1' => '3kqz7kx610uewmcwyg44z',
            ],
        ]);
        assertIsArray($response['get']);

        $this->assertSame('3kqz7kx610uewmcwyg44z', $response['get']['test-query-1'] ?? null);
    }

    public function testPostParameters(): void
    {
        $response = $this->createResponse('POST', [
            'form_params' => [
                'test-post-1' => '88lc5paair2x',
            ],
        ]);
        assertIsArray($response['post']);

        $this->assertSame('88lc5paair2x', $response['post']['test-post-1'] ?? null);
    }

    public function testCookiesParameters(): void
    {
        $response = $this->createResponse('POST', [
            'cookies' => CookieJar::fromArray(
                cookies: [
                    'test-cookie-1' => '94bt5trqjfqe6seo0',
                ],
                domain: TestPorts::connectHost(),
            ),
        ]);

        assertIsArray($response['cookies']);
        $this->assertSame('94bt5trqjfqe6seo0', $response['cookies']['test-cookie-1'] ?? null);
    }

    public function testFilesParameters(): void
    {
        $response = $this->createResponse('POST', [
            'headers' => [
                'Content-Type' => 'multipart/form-data; boundary=OEZCxUAIiopEcaUw',
            ],
            'body' => new MultipartStream(
                elements: [
                    [
                        'name' => 'test-file-1',
                        'filename' => 'test1.txt',
                        'contents' => 'b8owxkeuhjeq3kqz7kx610uewmcwygap',
                    ],
                ],
                boundary: 'OEZCxUAIiopEcaUw',
            ),
        ]);

        assertIsArray($response['files']);

        $this->assertSame('test1.txt', $response['files']['test-file-1']['filename'] ?? null);
        $this->assertSame('txt', $response['files']['test-file-1']['extension'] ?? null);
        $this->assertSame('b8owxkeuhjeq3kqz7kx610uewmcwygap', $response['files']['test-file-1']['content'] ?? null);
        $this->assertSame(32, $response['files']['test-file-1']['size'] ?? null);
        $this->assertSame(0, $response['files']['test-file-1']['error'] ?? null);
    }

    public function testMultipleFilesUpload(): void
    {
        $response = $this->createResponse('POST', [
            'headers' => [
                'Content-Type' => 'multipart/form-data; boundary=TestBoundary456',
            ],
            'body' => new MultipartStream(
                elements: [
                    [
                        'name' => 'documents[]',
                        'filename' => 'doc1.txt',
                        'contents' => 'content of document 1',
                    ],
                    [
                        'name' => 'documents[]',
                        'filename' => 'doc2.txt',
                        'contents' => 'content of document 2',
                    ],
                ],
                boundary: 'TestBoundary456',
            ),
        ]);

        assertIsArray($response['files']);

        $this->assertIsArray($response['files']['documents'] ?? null);
        $this->assertCount(2, $response['files']['documents']);

        $this->assertSame('doc1.txt', $response['files']['documents'][0]['filename'] ?? null);
        $this->assertSame('content of document 1', $response['files']['documents'][0]['content'] ?? null);
        $this->assertSame(21, $response['files']['documents'][0]['size'] ?? null);
        $this->assertSame(0, $response['files']['documents'][0]['error'] ?? null);

        $this->assertSame('doc2.txt', $response['files']['documents'][1]['filename'] ?? null);
        $this->assertSame('content of document 2', $response['files']['documents'][1]['content'] ?? null);
        $this->assertSame(21, $response['files']['documents'][1]['size'] ?? null);
        $this->assertSame(0, $response['files']['documents'][1]['error'] ?? null);
    }

    public function testNestedFileUploads(): void
    {
        $response = $this->createResponse('POST', [
            'headers' => [
                'Content-Type' => 'multipart/form-data; boundary=TestBoundary789',
            ],
            'body' => new MultipartStream(
                elements: [
                    [
                        'name' => 'user[avatar]',
                        'filename' => 'avatar.png',
                        'contents' => 'fake image content',
                    ],
                    [
                        'name' => 'user[resume]',
                        'filename' => 'resume.pdf',
                        'contents' => 'fake pdf content',
                    ],
                ],
                boundary: 'TestBoundary789',
            ),
        ]);

        assertIsArray($response['files']);

        $this->assertIsArray($response['files']['user'] ?? null);
        $this->assertSame('avatar.png', $response['files']['user']['avatar']['filename'] ?? null);
        $this->assertSame('resume.pdf', $response['files']['user']['resume']['filename'] ?? null);
    }

    public function testRawRequest(): void
    {
        $response = $this->createResponse('POST', [
            'body' => '88lc5paair2xwnidlz9r6k0rpggkmbhb2oqr0go0cxc',
        ]);

        $this->assertSame('88lc5paair2xwnidlz9r6k0rpggkmbhb2oqr0go0cxc', $response['raw_request']);
    }

    public function testJsonRequestPostBagIsEmpty(): void
    {
        $response = $this->createResponse('POST', [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode(['test-key' => 'test-value']),
        ]);

        $this->assertSame([], $response['post']);
        $this->assertSame('{"test-key":"test-value"}', $response['raw_request']);
    }

    public function testJsonRequestWithCharsetPostBagIsEmpty(): void
    {
        $response = $this->createResponse('POST', [
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8',
            ],
            'body' => json_encode(['test-key' => 'test-value']),
        ]);

        $this->assertSame([], $response['post']);
        $this->assertSame('{"test-key":"test-value"}', $response['raw_request']);
    }

    public function testMissingContentTypePostBagIsEmpty(): void
    {
        $response = $this->createResponse('POST', [
            'body' => 'test-body-content',
        ]);

        $this->assertSame([], $response['post']);
        $this->assertSame('test-body-content', $response['raw_request']);
    }

    public function testMultipartFormDataPostBagIsPopulated(): void
    {
        $response = $this->createResponse('POST', [
            'headers' => [
                'Content-Type' => 'multipart/form-data; boundary=TestBoundary123',
            ],
            'body' => "--TestBoundary123\r\nContent-Disposition: form-data; name=\"field1\"\r\n\r\nvalue1\r\n--TestBoundary123--",
        ]);

        $this->assertSame('value1', $response['post']['field1'] ?? null);
    }

    public function testFileUploadWithError(): void
    {
        $response = $this->createResponse('GET', [
            'query' => ['test' => 'file_with_error'],
        ], '/request_test_file_with_error');

        $this->assertTrue($response['optional_file_is_null'] ?? false);
        $this->assertTrue($response['valid_file_exists'] ?? false);
    }

    public function testFullPathSupport(): void
    {
        $response = $this->createResponse('GET', [
            'query' => ['test' => 'full_path'],
        ], '/request_test_full_path');

        $this->assertSame(2, $response['files_count'] ?? 0);
        $this->assertIsArray($response['files'] ?? null);

        $originalName1 = $response['files'][0]['original_name'] ?? null;
        $originalName2 = $response['files'][1]['original_name'] ?? null;

        $this->assertNotNull($originalName1);
        $this->assertNotNull($originalName2);

        $this->assertContains($originalName1, ['readme.txt', 'docs/readme.txt']);
        $this->assertContains($originalName2, ['config.json', 'config/config.json']);
    }

    public function testFileUploadWithErrorThroughRequestConverter(): void
    {
        $response = $this->createResponse('POST', [
            'headers' => [
                'Content-Type' => 'multipart/form-data; boundary=TestBoundaryError',
            ],
            'body' => new MultipartStream(
                elements: [
                    [
                        'name' => 'valid_file',
                        'filename' => 'test.txt',
                        'contents' => 'test content for valid file',
                    ],
                ],
                boundary: 'TestBoundaryError',
            ),
        ], '/request_test_upload_with_error');

        $this->assertTrue($response['valid_file_exists'] ?? false);
        $this->assertSame('test.txt', $response['valid_file_name'] ?? null);
        $this->assertSame(0, $response['valid_file_error'] ?? null);

        $this->assertTrue($response['optional_file_is_null'] ?? false);
    }

    public function testFullPathSupportThroughRequestConverter(): void
    {
        $response = $this->createResponse('POST', [
            'headers' => [
                'Content-Type' => 'multipart/form-data; boundary=TestBoundaryFullPath',
            ],
            'body' => new MultipartStream(
                elements: [
                    [
                        'name' => 'project_files[]',
                        'filename' => 'readme.txt',
                        'contents' => 'readme content',
                        'headers' => [
                            'Webkit-Relative-Path' => 'docs/readme.txt',
                        ],
                    ],
                    [
                        'name' => 'project_files[]',
                        'filename' => 'config.json',
                        'contents' => '{}',
                        'headers' => [
                            'Webkit-Relative-Path' => 'config/config.json',
                        ],
                    ],
                ],
                boundary: 'TestBoundaryFullPath',
            ),
        ], '/request_test_upload_full_path');

        $this->assertSame(2, $response['files_count'] ?? 0);
        $this->assertIsArray($response['files'] ?? null);

        $fileNames = array_column($response['files'], 'original_name');
        $this->assertContains('readme.txt', $fileNames);
        $this->assertContains('config.json', $fileNames);

        foreach ($response['files'] as $file) {
            $this->assertSame(0, $file['error'] ?? null);
        }
    }

    /**
     * @param mixed[] $options
     * @return mixed[]
     */
    private function createResponse(string $method, array $options = [], string $path = '/request_test'): array
    {
        $client = BoundedHttpClient::create();
        $response = $client->request($method, TestPorts::url(TestPorts::http(), $path), $options);

        $result = json_decode((string) $response->getBody(), true);
        assertIsArray($result);

        return $result;
    }
}
