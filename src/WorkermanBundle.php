<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

use CrazyGoat\WorkermanBundle\DependencyInjection\ConfigurationTreeBuilder;
use CrazyGoat\WorkermanBundle\DependencyInjection\ServicesConfigurator;
use CrazyGoat\WorkermanBundle\DependencyInjection\WorkermanCompilerPass;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class WorkermanBundle extends AbstractBundle
{
    public function __construct(
        private readonly ConfigurationTreeBuilder $configurationTreeBuilder = new ConfigurationTreeBuilder(),
        private readonly ServicesConfigurator $servicesConfigurator = new ServicesConfigurator(),
    ) {
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $this->configurationTreeBuilder->configure($definition);
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new WorkermanCompilerPass());
    }

    /**
     * @param mixed[] $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $this->servicesConfigurator->configure($config, $builder);

        $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'] = (string) $config['cache_warmup_timeout'];
    }
}
