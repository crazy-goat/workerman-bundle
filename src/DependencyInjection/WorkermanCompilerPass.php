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

        $tasks = array_map(fn(array $a): array => $a[0], $tasksTagged);
        $processes = array_map(fn(array $a): array => $a[0], $processesTagged);

        $configLoader = $container->getDefinition('workerman.config_loader');
        $configLoader
            ->addMethodCall('setProcessConfig', [$processes])
            ->addMethodCall('setSchedulerConfig', [$tasks]);

        $this->makeMiddlewaresPublic($container, $configLoader);

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
     * The server reads each middleware from the container by its ID at worker
     * start. A private service is removed from the compiled container, so the
     * worker would stop and be restarted again and again (issue #964). Make
     * the services from servers[].middlewares public, and fail at container
     * build time when one of them does not exist.
     */
    private function makeMiddlewaresPublic(ContainerBuilder $container, Definition $configLoader): void
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
