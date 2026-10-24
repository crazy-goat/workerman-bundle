<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Fixtures;

use CrazyGoat\WorkermanBundle\WorkermanBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * Minimal kernel for the Runner env-drift boot-level test (issue #1037).
 *
 * Loads an env-based `config/packages/workerman.yaml` from a temp project
 * dir, so the container build records the used env vars (via `%env()%`)
 * and the cache warmer snapshots them (issue #996).
 */
final class EnvDriftKernel extends BaseKernel
{
    use MicroKernelTrait;

    public function __construct(
        string $environment,
        bool $debug,
        private readonly string $testProjectDir,
    ) {
        parent::__construct($environment, $debug);
    }

    public function getProjectDir(): string
    {
        return $this->testProjectDir;
    }

    public function getCacheDir(): string
    {
        return $this->testProjectDir . '/var/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return $this->testProjectDir . '/var/log';
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
        $projectDir = $this->testProjectDir;
        $loader->load(static function (ContainerBuilder $container) use ($projectDir): void {
            $container->loadFromExtension('framework', [
                'secret' => 'env-drift-test',
            ]);

            $yamlLoader = new YamlFileLoader($container, new FileLocator($projectDir . '/config/packages'));
            $yamlLoader->load('workerman.yaml');
        });
    }
}
