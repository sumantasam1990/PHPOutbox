<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Symfony configuration definition for the Outbox bundle.
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('outbox');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('table_name')
                    ->defaultValue('outbox_messages')
                    ->info('Database table name for outbox messages')
                ->end()
                ->scalarNode('connection')
                    ->defaultNull()
                    ->info('Doctrine DBAL connection name (null = default)')
                ->end()
                ->arrayNode('relay')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('batch_size')
                            ->defaultValue(100)
                            ->info('Number of messages to fetch per relay cycle')
                        ->end()
                        ->integerNode('poll_interval_ms')
                            ->defaultValue(1000)
                            ->info('Milliseconds between poll cycles')
                        ->end()
                        ->integerNode('max_attempts')
                            ->defaultValue(5)
                            ->info('Maximum publish attempts before dead-lettering')
                        ->end()
                        ->integerNode('retry_backoff_ms')
                            ->defaultValue(2000)
                            ->info('Base backoff in ms between retries')
                        ->end()
                    ->end()
                ->end()
                ->integerNode('prune_after_days')
                    ->defaultValue(30)
                    ->info('Days to keep processed messages')
                ->end()
                ->booleanNode('delete_on_publish')
                    ->defaultFalse()
                    ->info('Delete messages on publish instead of marking as published')
                ->end()
                ->integerNode('lock_timeout_seconds')
                    ->defaultValue(30)
                    ->info('Timeout for row-level locks')
                ->end()
                ->scalarNode('id_generator')
                    ->defaultValue('uuid7')
                    ->info('ID generation strategy: uuid7 or ulid')
                ->end()
            ->end();

        return $treeBuilder;
    }
}
