<?php

declare(strict_types=1);

namespace Iperson1337\AssetAssignmentBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('iperson1337_asset_assignment');
        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('progress_cache_pool')
                    ->info('Пул кеша для прогресса выполнения. Должен быть общим для веб-подов и воркера: '
                        . 'файловый cache.app у каждого пода свой, и веб не видит, что пишет воркер.')
                    ->defaultValue('opendxp.cache.pool')
                    ->cannotBeEmpty()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
