<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\App;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class RequestTestController extends AbstractController
{
    #[Route('/request_test', name: 'app_request_test')]
    public function __invoke(Request $request): JsonResponse
    {
        return $this->json([
            'headers' => $request->headers->all(),
            'get' => $request->query->all(),
            'post' => $request->request->all(),
            'files' => $this->normalizeFiles($request->files->all()),
            'cookies' => $request->cookies->all(),
            'raw_request' => $request->getContent(),
        ]);
    }

    #[Route('/request_test_file_with_error', name: 'app_request_test_file_with_error')]
    public function testFileWithError(Request $request): JsonResponse
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmpFile, 'test content');

        $files = [
            'optional_file' => [
                'name' => '',
                'tmp_name' => '',
                'type' => '',
                'size' => 0,
                'error' => UPLOAD_ERR_NO_FILE,
            ],
            'valid_file' => [
                'name' => 'test.txt',
                'tmp_name' => $tmpFile,
                'type' => 'text/plain',
                'size' => 12,
                'error' => UPLOAD_ERR_OK,
            ],
        ];

        $testRequest = new Request(
            $request->query->all(),
            $request->request->all(),
            [],
            $request->cookies->all(),
            $files,
            $request->server->all(),
            $request->getContent(),
        );

        $result = [
            'optional_file_is_null' => $testRequest->files->get('optional_file') === null,
            'valid_file_exists' => $testRequest->files->get('valid_file') instanceof UploadedFile,
        ];

        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }

        return $this->json($result);
    }

    #[Route('/request_test_upload_with_error', name: 'app_request_test_upload_with_error')]
    public function testUploadWithError(Request $request): JsonResponse
    {
        $optionalFile = $request->files->get('optional_file');
        $validFile = $request->files->get('valid_file');

        return $this->json([
            'optional_file_is_null' => $optionalFile === null,
            'valid_file_exists' => $validFile instanceof UploadedFile,
            'valid_file_name' => $validFile instanceof UploadedFile ? $validFile->getClientOriginalName() : null,
            'valid_file_error' => $validFile instanceof UploadedFile ? $validFile->getError() : null,
        ]);
    }

    #[Route('/request_test_full_path', name: 'app_request_test_full_path')]
    public function testFullPath(Request $request): JsonResponse
    {
        $tmpFile1 = tempnam(sys_get_temp_dir(), 'readme_');
        $tmpFile2 = tempnam(sys_get_temp_dir(), 'config_');
        file_put_contents($tmpFile1, 'readme content');
        file_put_contents($tmpFile2, '{}');

        $files = [
            'project_files' => [
                [
                    'name' => 'readme.txt',
                    'tmp_name' => $tmpFile1,
                    'type' => 'text/plain',
                    'size' => 100,
                    'error' => UPLOAD_ERR_OK,
                    'full_path' => 'docs/readme.txt',
                ],
                [
                    'name' => 'config.json',
                    'tmp_name' => $tmpFile2,
                    'type' => 'application/json',
                    'size' => 50,
                    'error' => UPLOAD_ERR_OK,
                    'full_path' => 'config/config.json',
                ],
            ],
        ];

        $testRequest = new Request(
            $request->query->all(),
            $request->request->all(),
            [],
            $request->cookies->all(),
            $files,
            $request->server->all(),
            $request->getContent(),
        );

        $projectFiles = $testRequest->files->get('project_files');
        $result = [];
        if (is_array($projectFiles)) {
            foreach ($projectFiles as $file) {
                if ($file instanceof UploadedFile) {
                    $result[] = [
                        'original_name' => $file->getClientOriginalName(),
                        'full_path' => $file->getClientOriginalName(),
                    ];
                }
            }
        }

        $response = $this->json([
            'files_count' => count($result),
            'files' => $result,
        ]);

        if (file_exists($tmpFile1)) {
            unlink($tmpFile1);
        }
        if (file_exists($tmpFile2)) {
            unlink($tmpFile2);
        }

        return $response;
    }

    #[Route('/request_test_upload_full_path', name: 'app_request_test_upload_full_path')]
    public function testUploadFullPath(Request $request): JsonResponse
    {
        $projectFiles = $request->files->get('project_files');
        $result = [];
        $filesCount = 0;

        if (is_array($projectFiles)) {
            $filesCount = count($projectFiles);
            foreach ($projectFiles as $file) {
                if ($file instanceof UploadedFile) {
                    $result[] = [
                        'original_name' => $file->getClientOriginalName(),
                        'error' => $file->getError(),
                    ];
                }
            }
        }

        return $this->json([
            'files_count' => $filesCount,
            'files' => $result,
        ]);
    }

    /**
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    private function normalizeFiles(array $files): array
    {
        $result = [];
        foreach ($files as $name => $file) {
            $result[$name] = $this->normalizeFileEntry($file);
        }

        return $result;
    }

    /**
     * @param UploadedFile|array<string, mixed>|null $file
     * @return array<string, mixed>|null
     */
    private function normalizeFileEntry(UploadedFile|array|null $file): ?array
    {
        if ($file === null) {
            return null;
        }

        if (is_array($file)) {
            return array_map($this->normalizeFileEntry(...), $file);
        }

        return [
            'filename' => $file->getClientOriginalName(),
            'extension' => $file->getClientOriginalExtension(),
            'content' => $file->getContent(),
            'size' => $file->getSize(),
            'error' => $file->getError(),
        ];
    }
}
