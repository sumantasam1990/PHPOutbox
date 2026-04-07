<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Laravel;

use Illuminate\Support\ServiceProvider;
use PDO;
use PhpOutbox\Outbox\Contracts\IdGenerator;
use PhpOutbox\Outbox\Contracts\OutboxPublisher;
use PhpOutbox\Outbox\Contracts\OutboxSerializer;
use PhpOutbox\Outbox\Contracts\OutboxStore;
use PhpOutbox\Outbox\IdGenerator\UlidGenerator;
use PhpOutbox\Outbox\IdGenerator\UuidV7Generator;
use PhpOutbox\Outbox\Laravel\Commands\OutboxMigrateCommand;
use PhpOutbox\Outbox\Laravel\Commands\OutboxPruneCommand;
use PhpOutbox\Outbox\Laravel\Commands\OutboxRelayCommand;
use PhpOutbox\Outbox\Laravel\Publishers\LaravelQueuePublisher;
use PhpOutbox\Outbox\Outbox;
use PhpOutbox\Outbox\OutboxConfig;
use PhpOutbox\Outbox\Relay\OutboxRelay;
use PhpOutbox\Outbox\Serializer\JsonSerializer;
use PhpOutbox\Outbox\Store\PdoOutboxStore;

/**
 * Laravel service provider for the Outbox package.
 *
 * Auto-discovered via composer.json extra.laravel.providers.
 */
final class OutboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/outbox.php', 'outbox');

        $this->registerConfig();
        $this->registerIdGenerator();
        $this->registerSerializer();
        $this->registerStore();
        $this->registerPublisher();
        $this->registerOutbox();
        $this->registerRelay();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/config/outbox.php' => $this->app->configPath('outbox.php'),
            ], 'outbox-config');

            $this->commands([
                OutboxRelayCommand::class,
                OutboxMigrateCommand::class,
                OutboxPruneCommand::class,
            ]);
        }
    }

    private function registerConfig(): void
    {
        $this->app->singleton(OutboxConfig::class, function ($app): OutboxConfig {
            $config = $app['config']->get('outbox', []);

            return new OutboxConfig(
                tableName: $config['table_name'] ?? 'outbox_messages',
                batchSize: $config['relay']['batch_size'] ?? 100,
                pollIntervalMs: $config['relay']['poll_interval_ms'] ?? 1000,
                maxAttempts: $config['relay']['max_attempts'] ?? 5,
                retryBackoffMs: $config['relay']['retry_backoff_ms'] ?? 2000,
                pruneAfterDays: $config['prune_after_days'] ?? 30,
                deleteOnPublish: $config['delete_on_publish'] ?? false,
                lockTimeoutSeconds: $config['lock_timeout_seconds'] ?? 30,
            );
        });
    }

    private function registerIdGenerator(): void
    {
        $this->app->singleton(IdGenerator::class, function ($app): IdGenerator {
            $generator = $app['config']->get('outbox.id_generator', 'uuid7');

            return match ($generator) {
                'ulid' => new UlidGenerator(),
                default => new UuidV7Generator(),
            };
        });
    }

    private function registerSerializer(): void
    {
        $this->app->singleton(OutboxSerializer::class, function (): OutboxSerializer {
            return new JsonSerializer();
        });
    }

    private function registerStore(): void
    {
        $this->app->singleton(OutboxStore::class, function ($app): OutboxStore {
            $connection = $app['config']->get('outbox.connection');
            $dbConnection = $app['db']->connection($connection);

            /** @var PDO $pdo */
            $pdo = $dbConnection->getPdo();

            return new PdoOutboxStore($pdo, $app->make(OutboxConfig::class));
        });
    }

    private function registerPublisher(): void
    {
        $this->app->singleton(OutboxPublisher::class, function ($app): OutboxPublisher {
            return new LaravelQueuePublisher(
                queueConnection: $app['config']->get('outbox.publisher.queue_connection'),
                queueName: $app['config']->get('outbox.publisher.queue_name', 'outbox'),
            );
        });
    }

    private function registerOutbox(): void
    {
        $this->app->singleton(Outbox::class, function ($app): Outbox {
            return new Outbox(
                store: $app->make(OutboxStore::class),
                serializer: $app->make(OutboxSerializer::class),
                idGenerator: $app->make(IdGenerator::class),
            );
        });

        $this->app->alias(Outbox::class, 'outbox');
    }

    private function registerRelay(): void
    {
        $this->app->singleton(OutboxRelay::class, function ($app): OutboxRelay {
            return new OutboxRelay(
                store: $app->make(OutboxStore::class),
                publisher: $app->make(OutboxPublisher::class),
                config: $app->make(OutboxConfig::class),
                logger: $app->make('log'),
            );
        });
    }
}
