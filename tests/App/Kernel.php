<?php

declare(strict_types=1);

namespace Luzrain\WorkermanBundle\Test\App;

use Luzrain\WorkermanBundle\WorkermanBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

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
                        'name' => 'Test server',
                        'listen' => 'http://127.0.0.1:8888',
                        'processes' => 1,
                        'serve_files' => true,
                        'root_dir' => '%kernel.project_dir%/tests/data/',
                    ],
                ],
            ]);

            $container->register('kernel', self::class)
                ->addTag('controller.service_arguments')
                ->addTag('routing.route_loader')
            ;

            $container->autowire(ResponseTestController::class)->setAutoconfigured(true);
            $container->autowire(RequestTestController::class)->setAutoconfigured(true);
            $container->autowire(TestTask::class)->setAutoconfigured(true);
            $container->autowire(TestProcess::class)->setAutoconfigured(true);
        });
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('*.php', 'attribute');
    }
}
