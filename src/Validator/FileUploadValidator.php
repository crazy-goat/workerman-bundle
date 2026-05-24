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
     * @param array<string, mixed> $data
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
     * @param array<string, mixed> $data
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
        // Non-array file data is invalid
        if (!is_array($fileData)) {
            throw new FileUploadValidationException(
                sprintf(
                    'Malformed file upload data for field "%s": expected array, got %s',
                    $fieldName,
                    gettype($fileData),
                ),
            );
        }

        // Handle nested file arrays (e.g., files[field][])
        if (self::isFileList($fileData)) {
            foreach ($fileData as $index => $nestedFile) {
                if (!is_array($nestedFile)) {
                    throw new FileUploadValidationException(
                        sprintf(
                            'Malformed file upload data for field "%s" at index %s: expected array, got %s',
                            $fieldName,
                            $index,
                            gettype($nestedFile),
                        ),
                    );
                }
                self::validateSingleFileArray($fieldName . '[' . $index . ']', $nestedFile);
            }

            return;
        }

        // Check if this is a file structure or nested associative array (e.g., files[field][subfield])
        if (self::isSingleFileEntry($fileData)) {
            self::validateSingleFileArray($fieldName, $fileData);

            return;
        }

        // Nested associative array - validate each entry
        foreach ($fileData as $subFieldName => $subFileData) {
            if (is_array($subFileData)) {
                if (self::isFileList($subFileData)) {
                    // Array of files in nested field
                    foreach ($subFileData as $index => $nestedFile) {
                        if (is_array($nestedFile)) {
                            self::validateSingleFileArray($fieldName . '[' . $subFieldName . '][' . $index . ']', $nestedFile);
                        } else {
                            throw new FileUploadValidationException(
                                sprintf(
                                    'Malformed file upload data for field "%s[%s][%s]": expected array, got %s',
                                    $fieldName,
                                    $subFieldName,
                                    $index,
                                    gettype($nestedFile),
                                ),
                            );
                        }
                    }
                } elseif (self::isSingleFileEntry($subFileData)) {
                    // Single file in nested field
                    self::validateSingleFileArray($fieldName . '[' . $subFieldName . ']', $subFileData);
                } else {
                    // Unrecognized nested structure
                    throw new FileUploadValidationException(
                        sprintf(
                            'Malformed file upload data for field "%s[%s]": unrecognized structure. ' .
                            'Expected file keys (name, tmp_name) or array of files. Got keys: %s',
                            $fieldName,
                            $subFieldName,
                            implode(', ', array_keys($subFileData)),
                        ),
                    );
                }
            } else {
                throw new FileUploadValidationException(
                    sprintf(
                        'Malformed file upload data for field "%s[%s]": expected array, got %s',
                        $fieldName,
                        $subFieldName,
                        gettype($subFileData),
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
