# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-04-07

### Added

- **Core Library**
  - `Outbox` main entry point with `store()` and `storeMany()` methods
  - `OutboxConfig` readonly configuration value object with sensible defaults
  - `OutboxMessage` readonly value object with immutable state transitions
  - `OutboxMessageStatus` enum (Pending, Processing, Published, Failed, DeadLetter)
  - `PdoOutboxStore` with MySQL, PostgreSQL, and SQLite support
  - `SELECT ... FOR UPDATE SKIP LOCKED` for safe concurrent relay workers
  - `OutboxRelay` background poller with retry, exponential backoff, and dead-letter
  - `RelayMetrics` for per-cycle observability
  - `JsonSerializer` with strict error handling
  - `UuidV7Generator` (RFC 9562, time-ordered, zero dependencies)
  - `UlidGenerator` (Crockford Base32, time-ordered, zero dependencies)
  - `Schema` helper with DDL for MySQL, PostgreSQL, and SQLite
  - Typed exception hierarchy: `OutboxException`, `StoreException`, `PublishException`, `SerializationException`
  - Contract interfaces: `OutboxStore`, `OutboxPublisher`, `OutboxSerializer`, `IdGenerator`

- **Laravel Adapter**
  - Auto-discoverable `OutboxServiceProvider`
  - `Outbox` Facade
  - `artisan outbox:relay` command (daemon + `--once` mode)
  - `artisan outbox:migrate` command with driver detection
  - `artisan outbox:prune` command (schedulable)
  - `LaravelQueuePublisher` for dispatching to Laravel Queue
  - `OutboxEventSubscriber` for auto-capturing Laravel events
  - Publishable configuration file

- **Symfony Adapter**
  - `OutboxBundle` with DI extension
  - Configuration tree definition
  - `outbox:relay` and `outbox:prune` console commands
  - `MessengerPublisher` for Symfony Messenger

- **Documentation**
  - Functional Requirements Document (FRD)
  - Architecture deep-dive with flow diagrams
  - AI coding instructions for Gemini and Claude
  - Comprehensive README with quick start guides

- **Testing**
  - 70 PHPUnit tests (59 unit + 11 integration)
  - 183 assertions
  - SQLite in-memory for integration tests
  - Full lifecycle tests (store → relay → publish → retry → dead-letter)

- **Tooling**
  - PHPStan level 8 configuration
  - PHP-CS-Fixer with PER-CS2.0 standard
  - GitHub Actions CI workflow
