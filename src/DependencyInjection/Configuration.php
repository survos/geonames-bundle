<?php

declare(strict_types=1);

namespace Survos\GeonamesBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('survos_geonames');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('sqlite_dir')
                    ->defaultNull()
                    ->info('Directory containing the GeoNames SQLite databases.')
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
