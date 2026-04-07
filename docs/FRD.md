# Functional Requirements Document (FRD)

## Transactional Outbox Pattern for PHP

**Package:** `phpoutbox/outbox`  
**Version:** 1.0.0  
**Last Updated:** 2026-04-07  
**Status:** Draft

---

## 1. Problem Statement

Event-driven architectures in PHP suffer from the **dual-write problem**: when an application needs to write to a database AND publish an event to a message broker (queue), these are two separate operations that can fail independently.

### The Failure Scenario

```
1. Application writes business data to database     ✅ Success
2. Application dispatches event to queue             ❌ Queue fails (network timeout, broker down)
3. Event is lost forever — no retry, no trace        💀 Data inconsistency
```

This is not a theoretical risk — it happens regularly in production Laravel and Symfony applications. The event is simply lost, and downstream consumers never process it.

### Current State of PHP Ecosystem

| Ecosystem | Mature Outbox Implementations |
|-----------|-------------------------------|
| **Go**    | Multiple (watermill, etc.)    |
| **Node**  | Multiple (eventual, etc.)     |
| **Java**  | Debezium, Spring, Axon        |
| **PHP**   | ❌ No production-grade standalone package |

Existing PHP attempts:
- **Ecotone**: Tightly coupled to its own DDD framework
- **ludovicose/transaction-outbox**: Basic, unmaintained, Laravel-only
- **Symfony Messenger Doctrine transport**: Not a true outbox pattern implementation

---

## 2. Solution Overview

A framework-agnostic PHP package that implements the Transactional Outbox Pattern with first-class adapters for Laravel and Symfony.

### Core Principle

Write the event to an `outbox_messages` table **within the same database transaction** as the business data. A background relay process polls this table and publishes events to the external broker.

```
┌─────────────────────────────────────────────┐
│               Same DB Transaction            │
│                                              │
│  1. INSERT INTO orders (...)                 │
│  2. INSERT INTO outbox_messages (...)        │
│  3. COMMIT                                   │
│                                              │
│  ✅ Both written atomically                  │
│  ✅ If transaction fails, both roll back     │
└─────────────────────────────────────────────┘

┌─────────────────────────────────────────────┐
│         Background Relay Process             │
│                                              │
│  1. SELECT ... FOR UPDATE SKIP LOCKED        │
│  2. Publish to broker (Redis/SQS/RabbitMQ)   │
│  3. Mark as published                        │
│                                              │
│  ✅ At-least-once delivery guaranteed        │
│  ✅ Safe concurrent relay workers            │
└─────────────────────────────────────────────┘
```

---

## 3. Functional Requirements

### FR-1: Atomic Message Storage

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-1.1 | Store outbox messages within the same database transaction as business data | **P0** |
| FR-1.2 | Support single message storage (`store()`) | **P0** |
| FR-1.3 | Support batch message storage (`storeMany()`) | **P1** |
| FR-1.4 | Auto-generate time-ordered message IDs (UUID v7 / ULID) | **P0** |
| FR-1.5 | Serialize payloads to JSON by default | **P0** |
| FR-1.6 | Support custom serialization formats (Avro, Protobuf, etc.) | **P2** |
| FR-1.7 | Support custom metadata headers on messages | **P1** |

### FR-2: Background Relay (Poller)

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-2.1 | Fetch pending messages with configurable batch size | **P0** |
| FR-2.2 | Use row-level locking (`SELECT ... FOR UPDATE SKIP LOCKED`) for concurrent safety | **P0** |
| FR-2.3 | Publish messages via pluggable publisher interface | **P0** |
| FR-2.4 | Mark messages as published on success | **P0** |
| FR-2.5 | Support continuous daemon mode (infinite loop with configurable poll interval) | **P0** |
| FR-2.6 | Support single-pass mode (process once, exit) | **P1** |
| FR-2.7 | Graceful shutdown on SIGTERM/SIGINT signals | **P1** |

### FR-3: Retry & Dead-Letter

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-3.1 | Retry failed messages automatically (configurable max attempts) | **P0** |
| FR-3.2 | Track attempt count and last error per message | **P0** |
| FR-3.3 | Move messages to dead-letter status after exhausting retries | **P0** |
| FR-3.4 | Support exponential backoff between retries | **P1** |

### FR-4: Housekeeping

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-4.1 | Prune old published messages (configurable retention period) | **P1** |
| FR-4.2 | Prune dead-lettered messages | **P2** |
| FR-4.3 | Optional: delete messages immediately on publish (no audit trail) | **P2** |

### FR-5: Observability

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-5.1 | PSR-3 logging at every lifecycle stage | **P0** |
| FR-5.2 | Return metrics per relay cycle (processed, published, failed, dead-lettered, duration) | **P1** |
| FR-5.3 | Provide message count by status (`getCounts()`) | **P2** |

### FR-6: Laravel Integration

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-6.1 | Auto-discoverable ServiceProvider | **P0** |
| FR-6.2 | Publishable configuration file | **P0** |
| FR-6.3 | `artisan outbox:relay` command (daemon + single-pass) | **P0** |
| FR-6.4 | `artisan outbox:migrate` command (create outbox table) | **P0** |
| FR-6.5 | `artisan outbox:prune` command (schedulable) | **P1** |
| FR-6.6 | Laravel Queue publisher (dispatches to configured queue) | **P0** |
| FR-6.7 | Facade for convenient access | **P1** |
| FR-6.8 | Event subscriber for auto-capturing Laravel events | **P2** |

### FR-7: Symfony Integration

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-7.1 | Symfony Bundle with DI configuration | **P1** |
| FR-7.2 | Console commands for relay and pruning | **P1** |
| FR-7.3 | Symfony Messenger publisher | **P1** |

### FR-8: Framework-Agnostic Core

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-8.1 | Core library has zero framework dependencies | **P0** |
| FR-8.2 | Core depends only on PSR interfaces (psr/log, psr/clock) | **P0** |
| FR-8.3 | Works with raw PDO connections | **P0** |
| FR-8.4 | Support MySQL 8.0+ | **P0** |
| FR-8.5 | Support PostgreSQL 9.5+ | **P0** |
| FR-8.6 | Support SQLite (testing only) | **P1** |

---

## 4. Non-Functional Requirements

### NFR-1: Performance

- Batch fetch of 100 messages in < 10ms on indexed table
- Minimal overhead per message store (< 1ms additional)
- Relay should process 1,000+ messages/second on a single worker

### NFR-2: Concurrency

- Multiple relay workers MUST be able to run concurrently without processing the same message twice
- Achieved via `SELECT ... FOR UPDATE SKIP LOCKED`

### NFR-3: Idempotency

- The package guarantees **at-least-once delivery**
- Consumers MUST implement idempotent message handling
- Each message has a unique ID for deduplication

### NFR-4: Reliability

- Zero message loss when used correctly (within DB transaction)
- Graceful handling of broker failures (retry + dead-letter)

### NFR-5: Code Quality

- PHP 8.2+ with `declare(strict_types=1)` everywhere
- PHPStan level 8+
- 90%+ code coverage
- PER-CS2.0 coding standard

---

## 5. Database Schema

### Primary Table: `outbox_messages`

| Column | Type | Description |
|--------|------|-------------|
| `id` | VARCHAR(36) PK | Time-ordered UUID v7 or ULID |
| `aggregate_type` | VARCHAR(255) | Domain aggregate (e.g., "Order") |
| `aggregate_id` | VARCHAR(255) | Aggregate instance ID |
| `event_type` | VARCHAR(255) | Event name (e.g., "OrderCreated") |
| `payload` | JSON / JSONB | Serialized event data |
| `headers` | JSON / JSONB | Metadata (correlation ID, etc.) |
| `status` | VARCHAR(20) | pending / processing / published / failed / dead_letter |
| `attempts` | INT | Publish attempt counter |
| `last_error` | TEXT | Most recent error message |
| `created_at` | TIMESTAMP(6) | When the message was stored |
| `processed_at` | TIMESTAMP(6) | When published or dead-lettered |

### Indexes

| Name | Columns | Purpose |
|------|---------|---------|
| `idx_outbox_pending` | `(status, created_at)` | Fast relay polling |
| `idx_outbox_aggregate` | `(aggregate_type, aggregate_id)` | Query events by aggregate |
| `idx_outbox_prune` | `(status, processed_at)` | Fast pruning |

---

## 6. API Specification

### Storing Messages

```php
// Framework-agnostic
$outbox = new Outbox($store, $serializer, $idGenerator);

$pdo->beginTransaction();
// ... your business logic ...
$outbox->store('Order', $orderId, 'OrderCreated', ['total' => 99.99]);
$pdo->commit();

// Laravel
use PhpOutbox\Outbox\Laravel\Facades\Outbox;

DB::transaction(function () use ($order) {
    $order->save();
    Outbox::store('Order', $order->id, 'OrderCreated', $order->toArray());
});
```

### Running the Relay

```bash
# Laravel
php artisan outbox:relay          # Daemon mode
php artisan outbox:relay --once   # Single pass

# Symfony
bin/console outbox:relay
bin/console outbox:relay --once

# Framework-agnostic
$relay = new OutboxRelay($store, $publisher, $config, $logger);
$relay->run();      // Daemon
$relay->runOnce();  // Single pass
```

### Pruning

```bash
php artisan outbox:prune                # Default retention
php artisan outbox:prune --days=7       # Custom retention
php artisan outbox:prune --dead-letter  # Also prune dead-lettered
```

---

## 7. Configuration Reference

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `table_name` | string | `outbox_messages` | Database table name |
| `batch_size` | int | `100` | Messages per relay cycle |
| `poll_interval_ms` | int | `1000` | Ms between polls |
| `max_attempts` | int | `5` | Max publish attempts |
| `retry_backoff_ms` | int | `2000` | Base backoff between retries |
| `prune_after_days` | int | `30` | Retention period |
| `delete_on_publish` | bool | `false` | Delete vs mark on publish |
| `lock_timeout_seconds` | int | `30` | Row lock timeout |
| `id_generator` | string | `uuid7` | ID strategy: `uuid7` / `ulid` |

---

## 8. Security Considerations

- **SQL Injection**: All queries use prepared statements with parameterized values
- **Payload Size**: Large payloads should be stored by reference (S3 URL) not inline
- **Access Control**: Outbox table should only be writable by the application user
- **Sensitive Data**: Avoid storing PII in outbox payloads; use references/tokens instead
- **Error Messages**: Error messages stored in `last_error` are truncated to 2000 chars to prevent storage abuse

---

## 9. Future Considerations

- **CDC (Change Data Capture)**: Add support for Debezium-based relay (tail DB transaction log instead of polling)
- **Metrics Export**: Prometheus/StatsD integration for relay metrics
- **Dead Letter Dashboard**: Web UI for inspecting and replaying dead-lettered messages
- **Partitioned Polling**: Shard relay workers by aggregate type for higher throughput
- **Message Deduplication**: Built-in consumer-side dedup middleware
