<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Validator;

use CrazyGoat\WorkermanBundle\Exception\FileUploadValidationException;

final class FileUploadValidator
{
    /** @var list<string> */
    private const REQUIRED_FIELDS = ['name', 'tmp_name', 'type', 'size', 'error'];

    /**
     * @param array<mixed, mixed> $data
     */
    public static function isSingleFileEntry(array $data): bool
    {
        return isset($data['tmp_name']) || isset($data['name']);
    }

    /**
     * @param array<mixed, mixed> $data
     */
    public static function isFileList(array $data): bool
    {
        if ($data === []) {
            return false;
        }

        return array_is_list($data) && is_array($data[0]);
    }

    /**
     * @param array<string, mixed> $files
     * @throws FileUploadValidationException
     */
    public static function validate(array $files): void
    {
        if ($files === []) {
            return;
        }

        foreach ($files as $fieldName => $fileData) {
            self::validateFileEntry($fieldName, $fileData);
        }
    }

    /**
     * @param array<string, mixed> $file
     * @throws FileUploadValidationException
     */
    public static function assertRequiredFields(string $fieldName, array $file): void
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $file)) {
                throw new FileUploadValidationException(
                    sprintf(
                        'Malformed file upload data for field "%s": missing required field "%s". ' .
                        'Expected keys: %s. Got: %s',
                        $fieldName,
                        $field,
                        implode(', ', self::REQUIRED_FIELDS),
                        implode(', ', array_keys($file)),
                    ),
                );
            }
        }
    }

    public static function expectedArrayError(string $fieldName, mixed $value): FileUploadValidationException
    {
        return new FileUploadValidationException(
            sprintf(
                'Malformed file upload data for field "%s": expected array, got %s',
                $fieldName,
                gettype($value),
            ),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function unrecognizedStructureError(string $fieldName, array $data): FileUploadValidationException
    {
        return new FileUploadValidationException(
            sprintf(
                'Malformed file upload data for field "%s": unrecognized structure. ' .
                'Expected file keys (name, tmp_name) or array of files. Got keys: %s',
                $fieldName,
                implode(', ', array_keys($data)),
            ),
        );
    }

    /**
     * @throws FileUploadValidationException
     */
    private static function validateFileEntry(string $fieldName, mixed $fileData): void
    {
        if (!is_array($fileData)) {
            throw self::expectedArrayError($fieldName, $fileData);
        }

        if (self::isFileList($fileData)) {
            self::validateFileList($fieldName, $fileData);

            return;
        }

        if (self::isSingleFileEntry($fileData)) {
            self::assertRequiredFields($fieldName, $fileData);

            return;
        }

        self::validateNestedAssociative($fieldName, $fileData);
    }

    /**
     * @param array<int, mixed> $list
     * @throws FileUploadValidationException
     */
    private static function validateFileList(string $fieldName, array $list): void
    {
        foreach ($list as $index => $entry) {
            $entryFieldName = $fieldName . '[' . $index . ']';
            if (!is_array($entry)) {
                throw self::expectedArrayError($entryFieldName, $entry);
            }
            self::assertRequiredFields($entryFieldName, $entry);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @throws FileUploadValidationException
     */
    private static function validateNestedAssociative(string $fieldName, array $data): void
    {
        foreach ($data as $subFieldName => $subFileData) {
            $nestedFieldName = $fieldName . '[' . $subFieldName . ']';
            if (!is_array($subFileData)) {
                throw self::expectedArrayError($nestedFieldName, $subFileData);
            }

            if (self::isFileList($subFileData)) {
                self::validateFileList($nestedFieldName, $subFileData);
            } elseif (self::isSingleFileEntry($subFileData)) {
                self::assertRequiredFields($nestedFieldName, $subFileData);
            } else {
                throw self::unrecognizedStructureError($nestedFieldName, $subFileData);
            }
        }
    }
}
