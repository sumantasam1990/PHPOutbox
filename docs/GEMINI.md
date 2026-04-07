# GEMINI.md — AI Coding Instructions for Google Gemini

## Project Context

You are working on `phpoutbox/outbox`, a production-grade PHP package implementing the **Transactional Outbox Pattern**. This package solves the dual-write problem in event-driven architectures by writing events to an outbox table atomically with business data, then relaying them to a message broker via a background poller.

## Architecture

```
phpoutbox/
├── src/                    # Framework-agnostic core (zero external deps except PSR)
│   ├── Contracts/          # Interfaces: OutboxStore, OutboxPublisher, OutboxSerializer, IdGenerator
│   ├── Enum/               # OutboxMessageStatus (pending → processing → published/failed/dead_letter)
│   ├── Exception/          # Typed exceptions: OutboxException, StoreException, PublishException, SerializationException
│   ├── Message/            # OutboxMessage readonly value object
│   ├── Relay/              # OutboxRelay (poller), RelayMetrics
│   ├── Store/              # PdoOutboxStore (MySQL/PostgreSQL/SQLite), Schema
│   ├── Serializer/         # JsonSerializer
│   ├── IdGenerator/        # UuidV7Generator, UlidGenerator
│   ├── Outbox.php          # Main entry point — store()
│   └── OutboxConfig.php    # Configuration value object
├── src-laravel/            # Laravel adapter (ServiceProvider, Facade, Commands, Queue publisher)
├── src-symfony/            # Symfony adapter (Bundle, DI, Commands, Messenger publisher)
├── tests/                  # PHPUnit (Unit + Integration + Laravel)
└── docs/                   # FRD, ARCHITECTURE, GEMINI, CLAUDE
```

## Coding Standards

1. **PHP 8.2+** — Always use `declare(strict_types=1)` at the top of every file
2. **Readonly classes** for value objects (`OutboxMessage`, `OutboxConfig`, `RelayMetrics`)
3. **Enums** for status values (`OutboxMessageStatus`)
4. **Constructor property promotion** everywhere applicable
5. **Named arguments** for clarity in constructors with many parameters
6. **PSR-4 autoloading** via Composer
7. **PSR-3 logging** — inject `LoggerInterface`, use appropriate levels
8. **PSR-12 / PER-CS2.0** coding standard, enforced by PHP-CS-Fixer
9. **No `@var` annotations** — use typed properties and return types instead
10. **No magic methods** — explicit over implicit

## Key Design Decisions

### Why PDO (not Doctrine DBAL)?
PDO is built into PHP and has zero dependencies. Doctrine DBAL would add a heavy dependency that most users don't need. Users who want Doctrine integration can implement `OutboxStore` themselves.

### Why `SELECT ... FOR UPDATE SKIP LOCKED`?
This is the gold standard for concurrent queue processing:
- `FOR UPDATE` locks selected rows to prevent double-processing
- `SKIP LOCKED` causes other workers to skip already-locked rows instead of blocking
- Available in MySQL 8.0+ and PostgreSQL 9.5+

### Why UUID v7 (not UUID v4)?
UUID v4 is random and fragments B-tree indexes, causing poor INSERT performance on large tables. UUID v7 embeds a millisecond timestamp, making inserts always append to the end of the index.

### Why separate `src-laravel/` and `src-symfony/`?
Framework adapters are in separate directories (not nested under `src/`) to make it clear they're optional and to keep the core dependency-free. Users who only use vanilla PHP never load framework code.

### Why no direct RabbitMQ/Kafka adapter?
We ship only `LaravelQueuePublisher` (uses Laravel's queue system — Redis, SQS, database, or RabbitMQ via `laravel-queue-rabbitmq`) and `MessengerPublisher` (uses Symfony Messenger — supports AMQP, Redis, Doctrine natively). These two cover 95%+ of the PHP ecosystem. A direct RabbitMQ adapter would add a hard dependency (`php-amqplib/php-amqplib` or `ext-amqp`) for everyone, contradicting the zero-dependency philosophy. Users with custom broker needs implement `OutboxPublisher` — it's a simple interface with two methods.

## Common Tasks

### Adding a New Store Implementation
1. Create a new class in `src/Store/` implementing `OutboxStore`
2. Implement all methods from the interface
3. Add tests in `tests/Unit/Store/`
4. If it's a new database driver, add schema in `Schema.php`

### Adding a New Publisher
1. Create a new class implementing `OutboxPublisher`
2. Place framework-specific publishers in `src-laravel/Publishers/` or `src-symfony/Publisher/`
3. Framework-agnostic publishers go in `src/Publisher/` (create directory)
4. Always throw `PublishException` on failure — the relay depends on this for retry logic
5. **Do NOT ship direct broker adapters** (RabbitMQ, Kafka, etc.) as core — users implement `OutboxPublisher` for custom brokers
6. Consider adding a RabbitMQ example in the docs to guide users writing their own adapter

### Adding a New ID Generator
1. Implement `IdGenerator` interface
2. Place in `src/IdGenerator/`
3. Register in framework adapters (ServiceProvider, DI Extension)

### Modifying the Database Schema
1. Update `Schema.php` — all three static methods (mysql, postgresql, sqlite)
2. Update `PdoOutboxStore` if new columns are added
3. Update `OutboxMessage` value object
4. Update migration files in `database/migrations/`
5. Update FRD.md schema section

## Testing

```bash
# All tests
./vendor/bin/phpunit

# Unit tests only
./vendor/bin/phpunit --testsuite=unit

# Integration tests (uses SQLite in-memory)
./vendor/bin/phpunit --testsuite=integration

# Static analysis
./vendor/bin/phpstan analyse

# Code style check
./vendor/bin/php-cs-fixer fix --dry-run --diff
```

## Important: Never Do These

- ❌ Do NOT add framework dependencies to `src/` (core must stay framework-agnostic)
- ❌ Do NOT use UUID v4 for message IDs (poor index performance)
- ❌ Do NOT catch exceptions silently — always log and re-throw or handle explicitly
- ❌ Do NOT modify `OutboxMessage` properties after construction (it's `readonly`)
- ❌ Do NOT use `json_encode` without `JSON_THROW_ON_ERROR`
## Contributing

For more information on the contribution process, coding standards, and how to submit pull requests, please refer to [CONTRIBUTING.md](../CONTRIBUTING.md).
