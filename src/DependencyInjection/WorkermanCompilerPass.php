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
    public function process(ContainerBuilder $container): void
    {
        $tasksTagged = $container->findTaggedServiceIds('workerman.task');
        $processesTagged = $container->findTaggedServiceIds('workerman.process');
        $rebootStrategies = $container->findTaggedServiceIds('workerman.reboot_strategy');
        $responseConverterStrategies = $container->findTaggedServiceIds('workerman.response_converter.strategy');

        // Sort response converter strategies by priority (descending) so that
        // higher-priority strategies are checked first in ResponseConverter::convert().
        uasort($responseConverterStrategies, fn(array $a, array $b): int => ($b[0]['priority'] ?? 0) <=> ($a[0]['priority'] ?? 0));

        // Sort remaining tag sets by service ID for deterministic ordering in
        // the ServiceLocator registrations. Tasks and processes do not carry a
        // priority attribute; sorting by ID ensures reproducible container builds.
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
                null, // logger (optional)
                '%workerman.trusted_hosts%', // trusted hosts patterns
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
     * The jitter must be a whole number of seconds. A numeric string such as
     * '30' (from YAML) is cast to int; anything else fails at container build
     * time with a clear message (issue #970).
     *
     * @param array<string, mixed> $tag
     *
     * @return array<string, mixed>
     */
    private function normalizeTaskTag(string $id, array $tag): array
    {
        $jitter = $tag['jitter'] ?? null;
        if ($jitter === null || is_int($jitter)) {
            return $tag;
        }

        if (is_string($jitter) && preg_match('/^\d+$/', $jitter) === 1) {
            $tag['jitter'] = (int) $jitter;

            return $tag;
        }

        throw new InvalidArgumentException(sprintf('The "jitter" of the task "%s" must be a whole number of seconds, got %s.', $id, get_debug_type($jitter) . ' ' . var_export($jitter, true)));
    }

    /**
     * The server reads each middleware from the container by its ID at worker
     * start. A private service is removed from the compiled container, so the
     * worker would stop and be restarted again and again (issue #964). Make
     * the services from servers[].middlewares public, and fail at container
     * build time when one of them does not exist. Also record the root
     * directories of the StaticFilesMiddleware services as `static_roots`
     * in the config, for the check at server start (issue #965).
     */
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

    /**
     * Records the env vars used in the workerman config so a value changed
     * after warmup can re-warm the cache at server start (issue #996).
     *
     * The container resolves `%env()%` placeholders when the config loader
     * service is built (at warmup), so the resolved values frozen in the
     * cache would ignore `PORT` or `WORKERS` set later. The parameter bag
     * turns `%env()%` into unique placeholders first, then resolving with
     * a null format collects the used names without reading values — like
     * {@see staticRoot()} does — and the loader snapshots the values at
     * warmup and compares them again at start.
     */
    private function recordUsedEnvs(ContainerBuilder $container, Definition $configLoader): void
    {
        foreach ($configLoader->getMethodCalls() as [$method, $arguments]) {
            if ($method !== 'setWorkermanConfig' || !is_array($arguments[0] ?? null)) {
                continue;
            }

            $usedEnvs = [];
            $resolved = $container->getParameterBag()->resolveValue($arguments[0]);
            $container->resolveEnvPlaceholders($resolved, null, $usedEnvs);
            $configLoader->addMethodCall('setWorkermanEnvVarNames', [array_values($usedEnvs ?? [])]);

            return;
        }
    }

    /**
     * StaticFilesMiddleware throws in its constructor when the root directory
     * is missing. That happens at worker start, so the workers would restart
     * again and again (issue #965). Return the root directory so that the
     * master process can check it before it forks the workers. The container
     * build does not check it: the directory may not exist yet there.
     */
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

        // The value of an env var is known only at runtime.
        $usedEnvs = [];
        $container->resolveEnvPlaceholders($root, null, $usedEnvs);

        return $usedEnvs === [] && !str_starts_with($root, 'phar://') ? $root : null;
    }

    /**
     * Creates a Reference map from tagged services for ServiceLocator registration.
     *
     * @param array<string, mixed> $taggedServices Associative array keyed by service IDs (values ignored, only keys used)
     *
     * @return array<string, Reference> Service id => Reference mapping
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
