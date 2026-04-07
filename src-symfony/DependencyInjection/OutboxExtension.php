<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Symfony\DependencyInjection;

use PhpOutbox\Outbox\Contracts\IdGenerator;
use PhpOutbox\Outbox\Contracts\OutboxPublisher;
use PhpOutbox\Outbox\Contracts\OutboxSerializer;
use PhpOutbox\Outbox\Contracts\OutboxStore;
use PhpOutbox\Outbox\IdGenerator\UlidGenerator;
use PhpOutbox\Outbox\IdGenerator\UuidV7Generator;
use PhpOutbox\Outbox\Outbox;
use PhpOutbox\Outbox\OutboxConfig;
use PhpOutbox\Outbox\Relay\OutboxRelay;
use PhpOutbox\Outbox\Serializer\JsonSerializer;
use PhpOutbox\Outbox\Store\PdoOutboxStore;
use PhpOutbox\Outbox\Symfony\Command\OutboxPruneCommand;
use PhpOutbox\Outbox\Symfony\Command\OutboxRelayCommand;
use PhpOutbox\Outbox\Symfony\Publisher\MessengerPublisher;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Symfony DI extension for the Outbox bundle.
 */
final class OutboxExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $this->registerConfig($container, $config);
        $this->registerIdGenerator($container, $config);
        $this->registerSerializer($container);
        $this->registerStore($container);
        $this->registerPublisher($container);
        $this->registerOutbox($container);
        $this->registerRelay($container);
        $this->registerCommands($container);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerConfig(ContainerBuilder $container, array $config): void
    {
        $definition = new Definition(OutboxConfig::class);
        $definition->setArguments([
            $config['table_name'],
            $config['relay']['batch_size'],
            $config['relay']['poll_interval_ms'],
            $config['relay']['max_attempts'],
            $config['relay']['retry_backoff_ms'],
            $config['prune_after_days'],
            $config['delete_on_publish'],
            $config['lock_timeout_seconds'],
        ]);

        $container->setDefinition(OutboxConfig::class, $definition);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerIdGenerator(ContainerBuilder $container, array $config): void
    {
        $class = match ($config['id_generator']) {
            'ulid' => UlidGenerator::class,
            default => UuidV7Generator::class,
        };

        $definition = new Definition($class);
        $container->setDefinition(IdGenerator::class, $definition);
    }

    private function registerSerializer(ContainerBuilder $container): void
    {
        $definition = new Definition(JsonSerializer::class);
        $container->setDefinition(OutboxSerializer::class, $definition);
    }

    private function registerStore(ContainerBuilder $container): void
    {
        $definition = new Definition(PdoOutboxStore::class);
        $definition->setArguments([
            new Reference('database_connection'),
            new Reference(OutboxConfig::class),
        ]);

        $container->setDefinition(OutboxStore::class, $definition);
    }

    private function registerPublisher(ContainerBuilder $container): void
    {
        $definition = new Definition(MessengerPublisher::class);
        $definition->setArguments([
            new Reference('messenger.default_bus'),
        ]);

        $container->setDefinition(OutboxPublisher::class, $definition);
    }

    private function registerOutbox(ContainerBuilder $container): void
    {
        $definition = new Definition(Outbox::class);
        $definition->setArguments([
            new Reference(OutboxStore::class),
            new Reference(OutboxSerializer::class),
            new Reference(IdGenerator::class),
        ]);

        $container->setDefinition(Outbox::class, $definition);
        $container->setAlias('outbox', Outbox::class);
    }

    private function registerRelay(ContainerBuilder $container): void
    {
        $definition = new Definition(OutboxRelay::class);
        $definition->setArguments([
            new Reference(OutboxStore::class),
            new Reference(OutboxPublisher::class),
            new Reference(OutboxConfig::class),
            new Reference('logger'),
        ]);

        $container->setDefinition(OutboxRelay::class, $definition);
    }

    private function registerCommands(ContainerBuilder $container): void
    {
        $relay = new Definition(OutboxRelayCommand::class);
        $relay->setArguments([new Reference(OutboxRelay::class)]);
        $relay->addTag('console.command');
        $container->setDefinition(OutboxRelayCommand::class, $relay);

        $prune = new Definition(OutboxPruneCommand::class);
        $prune->setArguments([
            new Reference(OutboxStore::class),
            new Reference(OutboxConfig::class),
        ]);
        $prune->addTag('console.command');
        $container->setDefinition(OutboxPruneCommand::class, $prune);
    }
}
