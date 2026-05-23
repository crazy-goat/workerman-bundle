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

    private function resolvePropertyValue(BinaryFileResponse $response, string $name): mixed
    {
        $property = $this->resolveProperty($response, $name);

        return $property?->getValue($response);
    }

    private function resolveProperty(BinaryFileResponse $response, string $name): ?\ReflectionProperty
    {
        $class = $response::class;

        if (!isset(self::$propertyCache[$class][$name])) {
            try {
                $reflection = new \ReflectionClass($response);
                $property = $reflection->getProperty($name);
                self::$propertyCache[$class][$name] = $property;
            } catch (\ReflectionException) {
                self::$propertyCache[$class][$name] = null;
            }
        }

        return self::$propertyCache[$class][$name];
    }
}
