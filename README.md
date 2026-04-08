# PHPOutbox — Transactional Outbox for PHP

[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![PHPStan Level 8](https://img.shields.io/badge/PHPStan-Level%208-brightgreen.svg)](phpstan.neon)

**Stop losing events.** PHPOutbox implements the [Transactional Outbox Pattern](https://microservices.io/patterns/data/transactional-outbox.html) for PHP — guaranteed at-least-once event delivery for Laravel, Symfony, and vanilla PHP applications.

## The Problem

```php
// ❌ DANGEROUS — dual-write problem
DB::transaction(function () use ($order) {
    $order->save();
});
// If the app crashes here, or the queue is down...
event(new OrderCreated($order));  // 💀 Event lost forever
```

## The Solution

```php
// ✅ SAFE — atomic outbox write
DB::transaction(function () use ($order) {
    $order->save();
    Outbox::store('Order', $order->id, 'OrderCreated', $order->toArray());
    // Both written in the SAME transaction — both succeed or both fail
});

// Background relay publishes to your queue — guaranteed delivery
// php artisan outbox:relay
```

## Features

- **Atomic writes** — Events stored in the same DB transaction as business data
- **Background relay** — Polls outbox table, publishes to your queue
- **Retry with backoff** — Failed publishes retry automatically
- **Dead letter queue** — Messages moved to dead-letter after max retries
- **Concurrent workers** — Multiple relays via `SELECT FOR UPDATE SKIP LOCKED`
- **Framework-agnostic** — Core works with raw PDO, zero framework deps
- **Laravel adapter** — ServiceProvider, Facade, Artisan commands
- **Symfony adapter** — Bundle, Console commands, Messenger integration
- **Observability** — PSR-3 logging, relay metrics per cycle
- **Housekeeping** — Auto-prune old messages

## Requirements

- PHP 8.2+
- MySQL 8.0+ or PostgreSQL 9.5+ (SQLite for testing)
- PDO extension

## Installation

```bash
composer require sumantasam1990/phpoutbox
```

## Quick Start

### Laravel

**1. Publish config:**

```bash
php artisan vendor:publish --tag=outbox-config
```

**2. Create the outbox table:**

```bash
php artisan outbox:migrate
```

**3. Store events (inside your DB transaction):**

```php
use PhpOutbox\Outbox\Laravel\Facades\Outbox;
use Illuminate\Support\Facades\DB;

DB::transaction(function () {
    $order = Order::create([
        'customer_id' => 42,
        'total' => 199.99,
    ]);

    Outbox::store(
        aggregateType: 'Order',
        aggregateId: (string) $order->id,
        eventType: 'OrderCreated',
        payload: $order->toArray(),
        headers: ['correlation-id' => request()->header('X-Correlation-ID')],
    );
});
```

**4. Run the relay daemon:**

```bash
php artisan outbox:relay
```

Or for cron-based relay:

```bash
php artisan outbox:relay --once
```

**5. Schedule pruning in `routes/console.php`:**

```php
Schedule::command('outbox:prune --days=30')->daily();
```

### Symfony

**1. Register the bundle:**

```php
// config/bundles.php
return [
    // ...
    PhpOutbox\Outbox\Symfony\OutboxBundle::class => ['all' => true],
];
```

**2. Configure:**

```yaml
# config/packages/outbox.yaml
outbox:
    table_name: outbox_messages
    relay:
        batch_size: 100
        poll_interval_ms: 1000
        max_attempts: 5
```

**3. Run the relay:**

```bash
bin/console outbox:relay
```

### Vanilla PHP (No Framework)

```php
use PhpOutbox\Outbox\Outbox;
use PhpOutbox\Outbox\OutboxConfig;
use PhpOutbox\Outbox\Store\PdoOutboxStore;
use PhpOutbox\Outbox\Store\Schema;
use PhpOutbox\Outbox\Relay\OutboxRelay;

// Setup
$pdo = new PDO('mysql:host=localhost;dbname=myapp', 'user', 'pass');
$config = new OutboxConfig(batchSize: 50, maxAttempts: 3);
$store = new PdoOutboxStore($pdo, $config);
$outbox = new Outbox($store);

// Create table (one-time)
$pdo->exec(Schema::mysql());

// Store an event (inside your transaction)
$pdo->beginTransaction();
$pdo->exec("INSERT INTO orders (id, total) VALUES (1, 99.99)");
$outbox->store('Order', '1', 'OrderCreated', ['total' => 99.99]);
$pdo->commit();

// Run relay (implement OutboxPublisher for your broker)
$publisher = new MyRabbitMQPublisher();
$relay = new OutboxRelay($store, $publisher, $config);
$relay->run(); // Blocks forever — run in a supervisor
```

## Configuration

### Laravel Config (`config/outbox.php`)

| Key | Env Variable | Default | Description |
|-----|-------------|---------|-------------|
| `table_name` | `OUTBOX_TABLE` | `outbox_messages` | Outbox table name |
| `connection` | `OUTBOX_CONNECTION` | `null` (default) | Database connection |
| `relay.batch_size` | `OUTBOX_BATCH_SIZE` | `100` | Messages per relay cycle |
| `relay.poll_interval_ms` | `OUTBOX_POLL_INTERVAL` | `1000` | Ms between polls |
| `relay.max_attempts` | `OUTBOX_MAX_ATTEMPTS` | `5` | Max retries before dead-letter |
| `publisher.queue_connection` | `OUTBOX_QUEUE_CONNECTION` | `null` | Queue connection |
| `publisher.queue_name` | `OUTBOX_QUEUE_NAME` | `outbox` | Queue name |
| `prune_after_days` | `OUTBOX_PRUNE_DAYS` | `30` | Days to keep published messages |
| `delete_on_publish` | `OUTBOX_DELETE_ON_PUBLISH` | `false` | Delete vs mark as published |
| `id_generator` | `OUTBOX_ID_GENERATOR` | `uuid7` | ID strategy: `uuid7` or `ulid` |

## Custom Publisher

Implement `OutboxPublisher` for your message broker:

```php
use PhpOutbox\Outbox\Contracts\OutboxPublisher;
use PhpOutbox\Outbox\Exception\PublishException;
use PhpOutbox\Outbox\Message\OutboxMessage;

class RabbitMQPublisher implements OutboxPublisher
{
    public function __construct(private AMQPChannel $channel) {}

    public function publish(OutboxMessage $message): void
    {
        try {
            $this->channel->basic_publish(
                new AMQPMessage($message->payload),
                'events',
                $message->eventType,
            );
        } catch (\Throwable $e) {
            throw PublishException::failed($message->id, $e);
        }
    }

    public function publishBatch(array $messages): void
    {
        foreach ($messages as $message) {
            $this->publish($message);
        }
    }
}
```

## Monitoring

The relay returns metrics per cycle:

```php
$metrics = $relay->runOnce();

echo $metrics->summary();
// "Cycle #42: 100 processed (98 published, 1 failed, 1 dead-lettered) in 45.2ms"

$metrics->processed;    // Total messages handled
$metrics->published;    // Successfully published
$metrics->failed;       // Failed (will retry)
$metrics->deadLettered; // Exhausted retries
$metrics->durationMs;   // Cycle duration
```

## Architecture

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for detailed flow diagrams, concurrency model, and extension points.

## Testing

```bash
# All tests
./vendor/bin/phpunit

# Unit tests only
./vendor/bin/phpunit --testsuite=unit

# Integration tests (SQLite in-memory)
./vendor/bin/phpunit --testsuite=integration

# Static analysis
./vendor/bin/phpstan analyse
```

## Contributing

Contributions are welcome! Please read our [Contributing Guide](CONTRIBUTING.md) for details on our code of conduct, how to submit pull requests, and our development process.

1. Fork the repository
2. Create a feature branch
3. Write tests for your changes
4. Ensure all tests pass and PHPStan is clean
5. Submit a pull request

## License

MIT License. See [LICENSE](LICENSE) for details.
