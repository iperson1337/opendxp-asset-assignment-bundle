<?php

declare(strict_types=1);

namespace Iperson1337\AssetAssignmentBundle\DependencyInjection;

use Iperson1337\AssetAssignmentBundle\Service\AssetAssignmentService;
use Iperson1337\AssetAssignmentBundle\Service\RunningAssetAssignmentProvider;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

final class AssetAssignmentExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../../config')
        );
        $loader->load('services.yaml');

        $config = $this->processConfiguration(new Configuration(), $configs);
        $progressCache = new Reference($config['progress_cache_pool']);
        $container->getDefinition(AssetAssignmentService::class)->setArgument('$cache', $progressCache);
        $container->getDefinition(RunningAssetAssignmentProvider::class)->setArgument('$cache', $progressCache);
    }

    public function getAlias(): string
    {
        return 'iperson1337_asset_assignment';
    }
}
