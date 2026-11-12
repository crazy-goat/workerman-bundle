<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\App;

use CrazyGoat\WorkermanBundle\WorkermanBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getCacheDir(): string
    {
        $cacheDir = $_SERVER['APP_CACHE_DIR'] ?? null;
        if (\is_string($cacheDir) && $cacheDir !== '') {
            return $cacheDir . '/' . $this->environment;
        }

        return parent::getCacheDir();
    }

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new WorkermanBundle(),
        ];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'test' => true,
                'router' => [
                    'resource' => 'kernel::loadRoutes',
                    'type' => 'service',
                ],
            ]);

            $container->loadFromExtension('workerman', [
                'servers' => [
                    [
                        'name' => 'Test server with files',
                        'listen' => TestPorts::listen(TestPorts::http()),
                        'processes' => 1,
                        'serve_files' => true,
                        'root_dir' => '%kernel.project_dir%/tests/data/',
                    ],
                    [
                        'name' => 'Test server no files',
                        'listen' => TestPorts::listen(TestPorts::second()),
                        'processes' => 1,
                        'serve_files' => false,
                        'middlewares' => [
                            'first_middleware', 'second_middleware', 'third_middleware',
                        ],
                    ],
                    [
                        'name' => 'Test server middleware dispatch contract',
                        'listen' => TestPorts::listen(TestPorts::third()),
                        'processes' => 1,
                        'serve_files' => false,
                        'middlewares' => [
                            'dispatch_count_middleware',
                        ],
                    ],
                ],
            ]);

            $container->register('logger', NullLogger::class)
                ->setPublic(false);

            $container->register('kernel', self::class)
                ->addTag('controller.service_arguments')
                ->addTag('routing.route_loader')
            ;

            $container->autowire(ResponseTestController::class)->setAutoconfigured(true);
            $container->autowire(RequestTestController::class)->setAutoconfigured(true);
            $container->autowire(TestTask::class)->setAutoconfigured(true);
            $container->autowire(TestProcess::class)->setAutoconfigured(true);
            $container->autowire(TestErrorProcess::class)->setAutoconfigured(true);
            $container->autowire(ProcessEventRecorder::class)->setAutoconfigured(true)->setPublic(true);
            $container->setDefinition('first_middleware', (new Definition(TestMiddleware::class, ['X-First-Middleware', '1']))->setAutoconfigured(true)->setPublic(true));
            $container->setDefinition('second_middleware', (new Definition(TestMiddleware::class, ['X-Second-Middleware', '1']))->setAutoconfigured(true)->setPublic(true));
            $container->setDefinition('third_middleware', (new Definition(TestMiddleware::class, ['X-Third-Middleware', '1']))->setAutoconfigured(true)->setPublic(false));
            $container->setDefinition('dispatch_count_middleware', (new Definition(DispatchCountMiddleware::class))
                ->setArguments(['%kernel.project_dir%/var/dispatch_count'])
                ->setAutoconfigured(true)
                ->setPublic(true));
        });
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(__DIR__ . '/', 'attribute');
    }
}
