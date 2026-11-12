<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\DependencyInjection;

use CrazyGoat\WorkermanBundle\Http\HttpRequestHandler;
use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter;
use CrazyGoat\WorkermanBundle\Middleware\StaticFilesMiddleware;
use CrazyGoat\WorkermanBundle\Middleware\SymfonyController;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\StackRebootStrategy;
use CrazyGoat\WorkermanBundle\Scheduler\TaskHandler;
use CrazyGoat\WorkermanBundle\Supervisor\ProcessHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class WorkermanCompilerPass implements CompilerPassInterface
{
    private const MAX_INTERVAL_SECONDS = 31_536_000;

    public function process(ContainerBuilder $container): void
    {
        $tasksTagged = $container->findTaggedServiceIds('workerman.task');
        $processesTagged = $container->findTaggedServiceIds('workerman.process');
        $rebootStrategies = $container->findTaggedServiceIds('workerman.reboot_strategy');
        $responseConverterStrategies = $container->findTaggedServiceIds('workerman.response_converter.strategy');

        uasort($responseConverterStrategies, fn(array $a, array $b): int => ($b[0]['priority'] ?? 0) <=> ($a[0]['priority'] ?? 0));

        ksort($tasksTagged);
        ksort($processesTagged);
        ksort($rebootStrategies);

        $tasks = [];
        foreach ($tasksTagged as $id => $attributes) {
            $tasks[$id] = $this->normalizeTaskTag((string) $id, $attributes[0]);
        }
        $processes = array_map(fn(array $a): array => $a[0], $processesTagged);

        $configLoader = $container->getDefinition('workerman.config_loader');
        $configLoader
            ->addMethodCall('setProcessConfig', [$processes])
            ->addMethodCall('setSchedulerConfig', [$tasks]);

        $this->prepareMiddlewares($container, $configLoader);
        $this->recordUsedEnvs($container, $configLoader);

        $container
            ->register('workerman.task_locator', ServiceLocator::class)
            ->addTag('container.service_locator')
            ->setArguments([$this->referenceMap($tasks)]);

        $container
            ->register('workerman.process_locator', ServiceLocator::class)
            ->addTag('container.service_locator')
            ->setArguments([$this->referenceMap($processes)]);

        $container
            ->register('workerman.reboot_strategy', StackRebootStrategy::class)
            ->setArguments([$this->referenceMap($rebootStrategies)]);

        $container
            ->register('workerman.response_converter', ResponseConverter::class)
            ->setArguments([$this->referenceMap($responseConverterStrategies)]);

        $container
            ->register('workerman.symfony_controller', SymfonyController::class)
            ->setArguments([
                new Reference(KernelInterface::class),
                new Reference('workerman.response_converter'),
                null,
                '%workerman.trusted_hosts%',
            ]);

        $container->setAlias(SymfonyController::class, 'workerman.symfony_controller');

        $container
            ->register('workerman.http_request_handler', HttpRequestHandler::class)
            ->setPublic(true)
            ->setArguments([
                new Reference('workerman.symfony_controller'),
                new Reference('workerman.reboot_strategy'),
                new Reference('logger'),
            ]);

        $container
            ->register('workerman.task_handler', TaskHandler::class)
            ->setPublic(true)
            ->setArguments([
                new Reference('workerman.task_locator'),
                new Reference(EventDispatcherInterface::class),
            ]);

        $container
            ->register('workerman.process_handler', ProcessHandler::class)
            ->setPublic(true)
            ->setArguments([
                new Reference('workerman.process_locator'),
                new Reference(EventDispatcherInterface::class),
            ]);
    }

    /**
     * @param array<string, mixed> $tag
     * @return array<string, mixed>
     */
    private function normalizeTaskTag(string $id, array $tag): array
    {
        $schedule = $tag['schedule'] ?? null;
        if ($schedule !== null
            && !is_string($schedule)
            && !is_int($schedule)
            && !$schedule instanceof \DateInterval
            && !$schedule instanceof \DateTimeImmutable
        ) {
            throw $this->invalidSchedule($id, $schedule);
        }

        if (is_string($schedule) && preg_match('/^\s*[+-]?\d+(?:\.\d+)?\s*$/', $schedule) === 1
            && preg_match('/^\d+$/', $schedule) !== 1
        ) {
            throw $this->invalidSchedule($id, $schedule);
        }

        if (is_int($schedule) || (is_string($schedule) && preg_match('/^-?\d+$/', $schedule) === 1)) {
            $seconds = is_int($schedule) ? $schedule : (int) $schedule;
            if ($seconds < 1 || $seconds > self::MAX_INTERVAL_SECONDS) {
                throw $this->invalidSchedule($id, $schedule);
            }
            $tag['schedule'] = $seconds;
        }

        $jitter = $tag['jitter'] ?? null;
        if ($jitter === null) {
            return $tag;
        }

        if (is_int($jitter)) {
            if ($jitter < 0 || $jitter > self::MAX_INTERVAL_SECONDS) {
                throw $this->invalidJitter($id, $jitter);
            }

            return $tag;
        }

        if (is_string($jitter) && preg_match('/^-?\d+$/', $jitter) === 1) {
            $value = (int) $jitter;
            if ($value < 0 || $value > self::MAX_INTERVAL_SECONDS) {
                throw $this->invalidJitter($id, $jitter);
            }
            $tag['jitter'] = $value;

            return $tag;
        }

        throw new InvalidArgumentException(sprintf('The "jitter" of the task "%s" must be a whole number of seconds, got %s.', $id, get_debug_type($jitter) . ' ' . var_export($jitter, true)));
    }

    private function invalidSchedule(string $id, mixed $schedule): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'The "schedule" of the task "%s" must be an integer number of seconds from 1 to %d, got %s.',
            $id,
            self::MAX_INTERVAL_SECONDS,
            get_debug_type($schedule) . ' ' . var_export($schedule, true),
        ));
    }

    private function invalidJitter(string $id, mixed $jitter): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'The "jitter" of the task "%s" must be a whole number of seconds from 0 to %d, got %s.',
            $id,
            self::MAX_INTERVAL_SECONDS,
            get_debug_type($jitter) . ' ' . var_export($jitter, true),
        ));
    }

    private function prepareMiddlewares(ContainerBuilder $container, Definition $configLoader): void
    {
        $calls = $configLoader->getMethodCalls();
        foreach ($calls as $index => [$method, $arguments]) {
            if ($method !== 'setWorkermanConfig' || !is_array($arguments[0] ?? null)) {
                continue;
            }

            $staticRoots = [];

            $servers = $arguments[0]['servers'] ?? [];
            foreach (is_array($servers) ? $servers : [] as $server) {
                $middlewares = is_array($server) ? ($server['middlewares'] ?? []) : [];
                foreach (is_array($middlewares) ? $middlewares : [] as $id) {
                    if (!is_string($id)) {
                        continue;
                    }

                    if ($container->hasAlias($id)) {
                        $container->getAlias($id)->setPublic(true);
                        $staticRoots[] = $this->staticRoot($container, (string) $container->getAlias($id));
                    } elseif ($container->hasDefinition($id)) {
                        $container->getDefinition($id)->setPublic(true);
                        $staticRoots[] = $this->staticRoot($container, $id);
                    } else {
                        throw new InvalidArgumentException(sprintf('The middleware service "%s" from "workerman.servers[].middlewares" does not exist.', $id));
                    }
                }
            }

            $arguments[0]['static_roots'] = array_values(array_unique(array_filter($staticRoots)));
            $calls[$index] = [$method, $arguments];
        }

        $configLoader->setMethodCalls($calls);
    }

    private function recordUsedEnvs(ContainerBuilder $container, Definition $configLoader): void
    {
        $usedEnvs = [];
        foreach ($configLoader->getMethodCalls() as [$method, $arguments]) {
            if ($method === 'setWorkermanEnvVarNames' || !is_array($arguments[0] ?? null)) {
                continue;
            }

            $resolved = $container->getParameterBag()->resolveValue($arguments[0]);
            $container->resolveEnvPlaceholders($resolved, null, $usedEnvs);
        }

        $configLoader->addMethodCall('setWorkermanEnvVarNames', [array_values(array_unique($usedEnvs ?? []))]);
    }

    private function staticRoot(ContainerBuilder $container, string $id): ?string
    {
        if (!$container->hasDefinition($id)) {
            return null;
        }

        $definition = $container->getDefinition($id);
        if ($definition->getClass() !== StaticFilesMiddleware::class) {
            return null;
        }

        $arguments = $definition->getArguments();
        $root = $arguments['$rootDirectory'] ?? $arguments[0] ?? null;
        if (!is_string($root)) {
            return null;
        }

        $root = $container->getParameterBag()->resolveValue($root);
        if (!is_string($root)) {
            return null;
        }

        $usedEnvs = [];
        $container->resolveEnvPlaceholders($root, null, $usedEnvs);

        return $usedEnvs === [] && !str_starts_with($root, 'phar://') ? $root : null;
    }

    /**
     * @param array<string, mixed> $taggedServices
     * @return array<string, Reference>
     */
    private function referenceMap(array $taggedServices): array
    {
        $result = [];
        foreach (array_keys($taggedServices) as $id) {
            $result[$id] = new Reference($id);
        }
        return $result;
    }
}
