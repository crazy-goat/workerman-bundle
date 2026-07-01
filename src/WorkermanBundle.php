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
     * @param array{
     *     runtime_dir?: string,
     *     user?: string|null,
     *     group?: string|null,
     *     stop_timeout?: int,
     *     cache_warmup_timeout: int,
     *     status_timeout?: int,
     *     pid_file?: string,
     *     log_file?: string,
     *     stdout_file?: string,
     *     max_package_size?: int,
     *     connection_timeout?: int,
     *     keepalive_timeout?: int,
     *     response_chunk_size?: int,
     *     trusted_hosts?: list<string>,
     *     servers?: list<array{
     *         name: string,
     *         listen?: string|null,
     *         local_cert?: string|null,
     *         local_pk?: string|null,
     *         processes?: int|null,
     *         reuse_port?: bool,
     *         body_size_cap?: int|null,
     *         serve_files?: bool,
     *         root_dir?: string|null,
     *         middlewares?: list<string>,
     *         static_files?: array{allowed_extensions?: list<string>},
     *     }>,
     *     reload_strategy?: array{
     *         exception?: array{active?: bool, allowed_exceptions?: list<string>},
     *         max_requests?: array{active?: bool, requests?: int, dispersion?: int},
     *         file_monitor?: array{
     *             active?: bool,
     *             source_dir?: list<string>,
     *             file_pattern?: list<string>,
     *         },
     *         always?: array{active?: bool},
     *         memory?: array{
     *             active?: bool,
     *             limit?: int,
     *             gc_limit?: int,
     *             gc_cooldown?: int,
     *         },
     *     },
     *     build?: array{
     *         build_dir?: string,
     *         kernel_class?: string,
     *         phar_filename?: string,
     *         bin_filename?: string,
     *         bin_php_version?: string|null,
     *         sfx?: array{
     *             url?: string|null,
     *             file?: string|null,
     *             sha256?: string|null,
     *             allow_insecure?: bool,
     *         },
     *         exclude_patterns?: list<string>,
     *         exclude_files?: list<string>,
     *         custom_ini?: string|null,
     *     },
     * } $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $this->servicesConfigurator->configure($config, $builder);

        $timeout = $config['cache_warmup_timeout'];
        $envOverride = $_SERVER[CacheWarmupTimeoutConfig::ENV_VAR] ?? $_ENV[CacheWarmupTimeoutConfig::ENV_VAR] ?? null;
        if ($envOverride !== null && $envOverride !== '') {
            $timeout = (int) $envOverride;
        }

        CacheWarmupTimeoutConfig::set($timeout);
    }
}
