<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Handler;

use CrazyGoat\WorkermanBundle\Util\ServiceMethod;

/**
 * @internal
 */
trait ServiceHandlerTrait
{
    /**
     * @param class-string $startEventClass
     * @param class-string $errorEventClass
     */
    private function dispatchAndInvoke(
        ServiceMethod $serviceMethod,
        string $name,
        string $startEventClass,
        string $errorEventClass,
    ): void {
        /** @var array<string, bool> $methodExistsCache */
        static $methodExistsCache = [];

        $serviceInstance = $this->locator->get($serviceMethod->serviceId);
        \assert(\is_object($serviceInstance));

        $this->eventDispatcher->dispatch(new $startEventClass($serviceInstance::class, $name));

        try {
            $class = $serviceInstance::class;
            $method = $serviceMethod->method;
            $cacheKey = $class . '::' . $method;

            if (!isset($methodExistsCache[$cacheKey])) {
                $methodExistsCache[$cacheKey] = \method_exists($serviceInstance, $method);
            }

            if (!$methodExistsCache[$cacheKey]) {
                throw new \InvalidArgumentException(
                    \sprintf('Method "%s" does not exist on service "%s" (class "%s").', $method, $serviceMethod->serviceId, $class),
                );
            }
            $serviceInstance->{$method}();
        } catch (\Throwable $e) {
            $this->eventDispatcher->dispatch(new $errorEventClass($e, $serviceInstance::class, $name));
        }
    }
}
