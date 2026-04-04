<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Validator\FileUploadValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CrazyGoat\WorkermanBundle\Validator\FileUploadValidator
 */
final class FileUploadValidatorTest extends TestCase
{
    private FileUploadValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new FileUploadValidator();
    }

    public function testEmptyFilesArrayIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();
        // Should not throw - if we get here, validation passed
        $this->validator->validate([]);
    }

    public function testValidSingleFileIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();
        $files = [
            'test_file' => [
                'name' => 'test.txt',
                'tmp_name' => '/tmp/test123',
                'type' => 'text/plain',
                'size' => 12,
                'error' => 0,
            ],
        ];

        // Should not throw - if we get here, validation passed
        $this->validator->validate($files);
    }

    public function testValidMultipleFilesArrayIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();
        $files = [
            'files' => [
                [
                    'name' => 'file1.txt',
                    'tmp_name' => '/tmp/test1',
                    'type' => 'text/plain',
                    'size' => 10,
                    'error' => 0,
                ],
                [
                    'name' => 'file2.txt',
                    'tmp_name' => '/tmp/test2',
                    'type' => 'text/plain',
                    'size' => 20,
                    'error' => 0,
                ],
            ],
        ];

        // Should not throw - if we get here, validation passed
        $this->validator->validate($files);
    }

    public function testValidNestedAssociativeArrayIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();
        $files = [
            'user' => [
                'avatar' => [
                    'name' => 'avatar.png',
                    'tmp_name' => '/tmp/avatar',
                    'type' => 'image/png',
                    'size' => 1024,
                    'error' => 0,
                ],
                'resume' => [
                    'name' => 'resume.pdf',
                    'tmp_name' => '/tmp/resume',
                    'type' => 'application/pdf',
                    'size' => 2048,
                    'error' => 0,
                ],
            ],
        ];

        // Should not throw - if we get here, validation passed
        $this->validator->validate($files);
    }

    public function testValidNestedArrayOfFilesIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();
        $files = [
            'gallery' => [
                'images' => [
                    [
                        'name' => 'image1.jpg',
                        'tmp_name' => '/tmp/img1',
                        'type' => 'image/jpeg',
                        'size' => 100,
                        'error' => 0,
                    ],
                    [
                        'name' => 'image2.jpg',
                        'tmp_name' => '/tmp/img2',
                        'type' => 'image/jpeg',
                        'size' => 200,
                        'error' => 0,
                    ],
                ],
            ],
        ];

        // Should not throw - if we get here, validation passed
        $this->validator->validate($files);
    }

    public function testMissingRequiredFieldThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing required field');

        $files = [
            'malformed_file' => [
                'name' => 'test.txt',
                'tmp_name' => '/tmp/test',
                // Missing 'type', 'size', 'error'
            ],
        ];

        $this->validator->validate($files);
    }

    public function testNonArrayFileDataThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expected array, got');

        $files = [
            'invalid_file' => 'not an array',
        ];

        $this->validator->validate($files);
    }

    public function testUnrecognizedNestedStructureThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unrecognized structure');

        $files = [
            'nested' => [
                'unrecognized' => [
                    'foo' => 'bar',
                    // Missing file keys (name, tmp_name)
                ],
            ],
        ];

        $this->validator->validate($files);
    }

    public function testNonArrayInNestedFieldThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expected array, got');

        $files = [
            'nested' => [
                'invalid' => 'not an array',
            ],
        ];

        $this->validator->validate($files);
    }

    public function testNonArrayInIndexedNestedArrayThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unrecognized structure');

        $files = [
            'field' => [
                'subfield' => [
                    'not an array',
                ],
            ],
        ];

        $this->validator->validate($files);
    }

    public function testNonArrayInFileArrayThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expected array, got');

        $files = [
            'files' => [
                'not an array',
            ],
        ];

        $this->validator->validate($files);
    }

    public function testMissingNameFieldThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing required field "name"');

        $files = [
            'file' => [
                'tmp_name' => '/tmp/test',
                'type' => 'text/plain',
                'size' => 10,
                'error' => 0,
            ],
        ];

        $this->validator->validate($files);
    }

    public function testMissingTmpNameFieldThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing required field "tmp_name"');

        $files = [
            'file' => [
                'name' => 'test.txt',
                'type' => 'text/plain',
                'size' => 10,
                'error' => 0,
            ],
        ];

        $this->validator->validate($files);
    }

    public function testMissingTypeFieldThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing required field "type"');

        $files = [
            'file' => [
                'name' => 'test.txt',
                'tmp_name' => '/tmp/test',
                'size' => 10,
                'error' => 0,
            ],
        ];

        $this->validator->validate($files);
    }

    public function testMissingSizeFieldThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing required field "size"');

        $files = [
            'file' => [
                'name' => 'test.txt',
                'tmp_name' => '/tmp/test',
                'type' => 'text/plain',
                'error' => 0,
            ],
        ];

        $this->validator->validate($files);
    }

    public function testMissingErrorFieldThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing required field "error"');

        $files = [
            'file' => [
                'name' => 'test.txt',
                'tmp_name' => '/tmp/test',
                'type' => 'text/plain',
                'size' => 10,
            ],
        ];

        $this->validator->validate($files);
    }
}
