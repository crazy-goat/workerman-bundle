<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response\Strategy;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class BinaryFileResponseReflector
{
    /**
     * @var array<class-string, array<string, \ReflectionProperty|null>>
     */
    private static array $propertyCache = [];

    /** @var list<string> */
    private const TARGET_PROPERTIES = ['tempFileObject', 'offset', 'maxlen', 'deleteFileAfterSend'];

    public function getTempFileObject(BinaryFileResponse $response): ?\SplTempFileObject
    {
        $value = $this->resolvePropertyValue($response, 'tempFileObject');

        return $value instanceof \SplTempFileObject ? $value : null;
    }

    public function getOffset(BinaryFileResponse $response): ?int
    {
        return $this->resolvePropertyValue($response, 'offset');
    }

    public function getMaxlen(BinaryFileResponse $response): ?int
    {
        return $this->resolvePropertyValue($response, 'maxlen');
    }

    public function getDeleteFileAfterSend(BinaryFileResponse $response): ?bool
    {
        return $this->resolvePropertyValue($response, 'deleteFileAfterSend');
    }

    public static function resetCache(): void
    {
        self::$propertyCache = [];
    }

    private function resolvePropertyValue(BinaryFileResponse $response, string $name): mixed
    {
        $class = $response::class;
        $this->ensurePropertiesCached($class);

        return self::$propertyCache[$class][$name]?->getValue($response);
    }

    /**
     * @param class-string $class
     */
    private function ensurePropertiesCached(string $class): void
    {
        if (isset(self::$propertyCache[$class])) {
            return;
        }

        self::$propertyCache[$class] = [];

        $reflection = new \ReflectionClass($class);

        foreach (self::TARGET_PROPERTIES as $name) {
            try {
                self::$propertyCache[$class][$name] = $reflection->getProperty($name);
            } catch (\ReflectionException) {
                self::$propertyCache[$class][$name] = null;
            }
        }
    }
}
