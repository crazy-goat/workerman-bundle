<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Validator;

use CrazyGoat\WorkermanBundle\Exception\FileUploadValidationException;

/**
 * Validates the structure of uploaded files array.
 *
 * This validator ensures that file upload data follows the expected structure
 * with required fields (name, tmp_name, type, size, error) and provides
 * clear error messages when validation fails.
 *
 * All methods are static as this validator has no state.
 */
final class FileUploadValidator
{
    /** @var list<string> Required fields for a single file upload */
    private const REQUIRED_FIELDS = ['name', 'tmp_name', 'type', 'size', 'error'];

    /**
     * Determine whether the given array is a single file entry.
     *
     * A single file entry has either 'tmp_name' or 'name' key.
     * This is the single source of truth for file-entry shape recognition,
     * shared between this validator and RequestConverter.
     *
     * @param array<mixed, mixed> $data
     */
    public static function isSingleFileEntry(array $data): bool
    {
        return isset($data['tmp_name']) || isset($data['name']);
    }

    /**
     * Determine whether the given array is a list of file entries.
     *
     * A file list is an indexed array whose first element is itself an array.
     *
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
     * Validate the structure of uploaded files array.
     * Workerman should always return properly structured file data, but this
     * provides clearer error messages if something goes wrong.
     *
     * @param array<string, mixed> $files
     *
     * @throws FileUploadValidationException if file structure is malformed
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
     * Validate a single file entry structure.
     *
     * @param string $fieldName The form field name
     * @param mixed  $fileData  The file data to validate
     *
     * @throws FileUploadValidationException if file structure is malformed
     */
    private static function validateFileEntry(string $fieldName, mixed $fileData): void
    {
        if (!is_array($fileData)) {
            throw new FileUploadValidationException(
                sprintf(
                    'Malformed file upload data for field "%s": expected array, got %s',
                    $fieldName,
                    gettype($fileData),
                ),
            );
        }

        if (self::isFileList($fileData)) {
            self::validateFileList($fieldName, $fileData);

            return;
        }

        if (self::isSingleFileEntry($fileData)) {
            self::validateSingleFileArray($fieldName, $fileData);

            return;
        }

        self::validateNestedAssociative($fieldName, $fileData);
    }

    /**
     * Validate an indexed list of file entries.
     *
     * @param string        $fieldName The form field name
     * @param array<int, mixed> $list      The list of file entries
     *
     * @throws FileUploadValidationException if file structure is malformed
     */
    private static function validateFileList(string $fieldName, array $list): void
    {
        foreach ($list as $index => $entry) {
            $entryFieldName = $fieldName . '[' . $index . ']';
            if (!is_array($entry)) {
                throw new FileUploadValidationException(
                    sprintf(
                        'Malformed file upload data for field "%s": expected array, got %s',
                        $entryFieldName,
                        gettype($entry),
                    ),
                );
            }
            self::validateSingleFileArray($entryFieldName, $entry);
        }
    }

    /**
     * Validate a nested associative array structure.
     *
     * Each sub-field is validated as a file list, single file entry, or falls through
     * to an unrecognized-structure error.
     *
     * @param string             $fieldName The form field name
     * @param array<string, mixed> $data      The nested associative array
     *
     * @throws FileUploadValidationException if file structure is malformed
     */
    private static function validateNestedAssociative(string $fieldName, array $data): void
    {
        foreach ($data as $subFieldName => $subFileData) {
            $nestedFieldName = $fieldName . '[' . $subFieldName . ']';
            if (!is_array($subFileData)) {
                throw new FileUploadValidationException(
                    sprintf(
                        'Malformed file upload data for field "%s": expected array, got %s',
                        $nestedFieldName,
                        gettype($subFileData),
                    ),
                );
            }

            if (self::isFileList($subFileData)) {
                self::validateFileList($nestedFieldName, $subFileData);
            } elseif (self::isSingleFileEntry($subFileData)) {
                self::validateSingleFileArray($nestedFieldName, $subFileData);
            } else {
                throw new FileUploadValidationException(
                    sprintf(
                        'Malformed file upload data for field "%s": unrecognized structure. ' .
                        'Expected file keys (name, tmp_name) or array of files. Got keys: %s',
                        $nestedFieldName,
                        implode(', ', array_keys($subFileData)),
                    ),
                );
            }
        }
    }

    /**
     * Validate a single file array has required fields.
     *
     * @param string               $fieldName The form field name
     * @param array<string, mixed> $file      The file array to validate
     *
     * @throws FileUploadValidationException if required fields are missing
     */
    private static function validateSingleFileArray(string $fieldName, array $file): void
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
}
