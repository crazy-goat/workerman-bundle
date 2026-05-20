<?php

declare(strict_types=1);

namespace App;

use CrazyGoat\WorkermanBundle\WorkermanBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class Kernel extends BaseKernel
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
                'http_method_override' => false,
            ]);

            $container->loadFromExtension('workerman', [
                'runtime_dir' => '%kernel.project_dir%/var',
                'servers' => [
                    [
                        'name' => 'Integration test server',
                        'listen' => 'http://127.0.0.1:8887',
                        'processes' => 1,
                        'serve_files' => false,
                    ],
                ],
            ]);

            $container->register('kernel', self::class)
                ->addTag('controller.service_arguments')
                ->addTag('routing.route_loader');
        });
    }

    public function getProjectDir(): string
    {
        if (defined('IN_PHAR') && IN_PHAR) {
            return dirname(\Phar::running(false));
        }

        return parent::getProjectDir();
    }

    public function getCacheDir(): string
    {
        if (defined('IN_PHAR') && IN_PHAR) {
            return $this->getProjectDir() . '/var/cache/' . $this->environment;
        }

        return parent::getCacheDir();
    }

    public function getLogDir(): string
    {
        if (defined('IN_PHAR') && IN_PHAR) {
            return $this->getProjectDir() . '/var/log';
        }

        return parent::getLogDir();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(__DIR__ . '/Controller/', 'attribute');
    }
}
