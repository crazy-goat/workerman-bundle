<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Validator;

use InvalidArgumentException;

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
     * Validate the structure of uploaded files array.
     * Workerman should always return properly structured file data, but this
     * provides clearer error messages if something goes wrong.
     *
     * @param array<string, mixed> $files
     *
     * @throws InvalidArgumentException if file structure is malformed
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
     * @throws InvalidArgumentException if file structure is malformed
     */
    private static function validateFileEntry(string $fieldName, mixed $fileData): void
    {
        // Non-array file data is invalid
        if (!is_array($fileData)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Malformed file upload data for field "%s": expected array, got %s',
                    $fieldName,
                    gettype($fileData),
                ),
            );
        }

        // Handle nested file arrays (e.g., files[field][])
        if (array_is_list($fileData) && count($fileData) > 0 && is_array($fileData[0])) {
            foreach ($fileData as $index => $nestedFile) {
                if (!is_array($nestedFile)) {
                    throw new InvalidArgumentException(
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
        if (isset($fileData['tmp_name']) || isset($fileData['name'])) {
            self::validateSingleFileArray($fieldName, $fileData);

            return;
        }

        // Nested associative array - validate each entry
        foreach ($fileData as $subFieldName => $subFileData) {
            if (is_array($subFileData)) {
                if (array_is_list($subFileData) && count($subFileData) > 0 && is_array($subFileData[0])) {
                    // Array of files in nested field
                    foreach ($subFileData as $index => $nestedFile) {
                        if (is_array($nestedFile)) {
                            self::validateSingleFileArray($fieldName . '[' . $subFieldName . '][' . $index . ']', $nestedFile);
                        } else {
                            throw new InvalidArgumentException(
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
                } elseif (isset($subFileData['tmp_name']) || isset($subFileData['name'])) {
                    // Single file in nested field
                    self::validateSingleFileArray($fieldName . '[' . $subFieldName . ']', $subFileData);
                } else {
                    // Unrecognized nested structure
                    throw new InvalidArgumentException(
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
                throw new InvalidArgumentException(
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
     * @throws InvalidArgumentException if required fields are missing
     */
    private static function validateSingleFileArray(string $fieldName, array $file): void
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $file)) {
                throw new InvalidArgumentException(
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
