# CLAUDE.md — AI Coding Instructions for Anthropic Claude

This file provides context and instructions for Claude when working on this codebase.

## Project Overview

`phpoutbox/outbox` is a **Transactional Outbox Pattern** implementation for PHP. It guarantees at-least-once event delivery by writing events to a database table atomically with business data, then publishing them via a background relay.

**The problem it solves:** In event-driven PHP apps (Laravel, Symfony), if you write to the DB then dispatch an event and the queue fails, the event is lost. The outbox pattern eliminates this by making the event part of the same DB transaction.

## Repository Structure

```
src/                    → Framework-agnostic core (depends only on psr/log, psr/clock)
  Contracts/            → Interfaces: OutboxStore, OutboxPublisher, OutboxSerializer, IdGenerator
  Enum/                 → OutboxMessageStatus enum (pending, processing, published, failed, dead_letter)
  Exception/            → Typed exception hierarchy (OutboxException → StoreException, PublishException, etc.)
  Message/              → OutboxMessage (readonly value object)
  Relay/                → OutboxRelay (background poller), RelayMetrics
  Store/                → PdoOutboxStore (MySQL, PostgreSQL, SQLite), Schema helper
  Serializer/           → JsonSerializer
  IdGenerator/          → UuidV7Generator, UlidGenerator
  Outbox.php            → Main API: store(), storeMany()
  OutboxConfig.php      → Configuration value object with defaults

src-laravel/            → Laravel adapter
  OutboxServiceProvider → Auto-discoverable, registers all bindings
  Facades/Outbox        → Static proxy
  Commands/             → outbox:relay, outbox:migrate, outbox:prune
  Publishers/           → LaravelQueuePublisher
  Listeners/            → OutboxEventSubscriber (auto-capture Laravel events)
  config/outbox.php     → Publishable config

src-symfony/            → Symfony adapter
  OutboxBundle          → Bundle registration
  DependencyInjection/  → Extension + Configuration tree
  Command/              → Console commands
  Publisher/            → MessengerPublisher

tests/
  Unit/                 → Mock-based tests for each component
  Integration/          → SQLite in-memory end-to-end tests
  Laravel/              → Orchestra Testbench tests (if needed)
```

## Code Conventions

### Strict Rules
- Every PHP file starts with `declare(strict_types=1);`
- PHP 8.2+ features: readonly classes, enums, constructor promotion, named arguments
- All exceptions extend `OutboxException` for consistent error handling
- Use `JSON_THROW_ON_ERROR` on every json_encode/json_decode call
- Use typed properties and return types everywhere — no `@var` docblock hacks
- PSR-4 autoloading, PSR-3 logging, PSR-12/PER-CS2.0 coding style

### Naming
- Interfaces in `Contracts/` directory, NOT prefixed with `Interface` suffix
- Value objects are `readonly class`
- Factory methods are static named constructors (`::create()`, `::fromArray()`)
- Immutable state transitions via `with*()` methods that return new instances

### Error Handling
- `StoreException` for database operation failures
- `PublishException` for broker publish failures (relay retries these)
- `SerializationException` for payload encoding/decoding failures
- All exceptions have static factory methods: `::storeFailed()`, `::failed()`, etc.

## Key Architectural Decisions

1. **PDO over Doctrine**: Zero dependencies, works everywhere
2. **SELECT FOR UPDATE SKIP LOCKED**: Safe concurrent relay workers on MySQL 8+ / PG 9.5+
3. **UUID v7 over UUID v4**: Time-ordered = B-tree friendly = no index fragmentation
4. **Separate src-laravel/src-symfony directories**: Core stays dependency-free
5. **At-least-once delivery**: Consumers must be idempotent
6. **Message status lifecycle**: Pending → Processing → Published | Failed → DeadLetter
7. **No direct broker adapters**: Ship only `LaravelQueuePublisher` and `MessengerPublisher` — these leverage Laravel Queue (Redis/SQS/RabbitMQ via drivers) and Symfony Messenger (AMQP/Redis/Doctrine) respectively, covering 95%+ of use cases. Direct RabbitMQ/Kafka adapters would add hard dependencies that contradict the zero-dependency core. Users implement `OutboxPublisher` for custom brokers. A RabbitMQ example should be provided in the docs as a reference.

## Build & Test Commands

```bash
composer install                          # Install dependencies
./vendor/bin/phpunit --testsuite=unit      # Unit tests
./vendor/bin/phpunit --testsuite=integration  # Integration tests (SQLite)
./vendor/bin/phpunit                       # All tests
./vendor/bin/phpstan analyse              # Static analysis (level 8)
./vendor/bin/php-cs-fixer fix --dry-run   # Code style check
./vendor/bin/php-cs-fixer fix             # Auto-fix code style
```

## When Modifying Code

1. **Adding to schema**: Update `Schema::mysql()`, `Schema::postgresql()`, and `Schema::sqlite()`. Update `OutboxMessage`. Update `PdoOutboxStore::hydrate()`.
2. **New publisher**: Implement `OutboxPublisher`. Throw `PublishException` on failure. Register in framework adapters. Do NOT add direct broker adapters (RabbitMQ, Kafka) to core — users implement `OutboxPublisher` for custom brokers.
3. **New store**: Implement `OutboxStore`. Must support row-level locking for concurrent relay. Add tests.
4. **Config changes**: Update `OutboxConfig`, framework config files, and `OutboxConfig::fromArray()`.

## Anti-Patterns to Avoid

- Adding framework deps to `src/` — core must be framework-agnostic
- Using UUID v4 — fragments indexes
- Silent exception swallowing — always log and handle
- Mutable state on value objects — use `readonly` + `with*()` pattern
- Direct `echo`/`print` — use PSR-3 logger
- Storing large blobs in outbox payload — use references (S3 URLs, etc.)
