# Architecture

## Transactional Outbox Pattern — Deep Dive

### Flow Diagram

```
┌──────────────────────────────────────────────────────────────┐
│                     YOUR APPLICATION                         │
│                                                              │
│  ┌─────────────────────────────────────────────────────────┐ │
│  │              Database Transaction                        │ │
│  │                                                          │ │
│  │  1. Business Write    INSERT INTO orders (...)           │ │
│  │  2. Outbox Write      INSERT INTO outbox_messages (...)  │ │
│  │  3. COMMIT            Both or neither                    │ │
│  │                                                          │ │
│  └─────────────────────────────────────────────────────────┘ │
│                                                              │
│  Outbox::store('Order', $orderId, 'OrderCreated', $data)     │
│                                                              │
└──────────────────────────────────────────────────────────────┘

                           ↕ (database)

┌──────────────────────────────────────────────────────────────┐
│                    OUTBOX RELAY (DAEMON)                      │
│                                                              │
│  ┌─────────────────────────────────────────────────────────┐ │
│  │  while (!$shouldStop) {                                  │ │
│  │    BEGIN TRANSACTION                                     │ │
│  │    SELECT ... FOR UPDATE SKIP LOCKED (batch of 100)      │ │
│  │    UPDATE status = 'processing'                          │ │
│  │    COMMIT                                                │ │
│  │                                                          │ │
│  │    foreach ($messages as $msg) {                          │ │
│  │      try {                                               │ │
│  │        $publisher->publish($msg)   // Push to broker     │ │
│  │        $store->markAsPublished()                          │ │
│  │      } catch (PublishException $e) {                      │ │
│  │        if ($msg->attempts >= maxAttempts)                  │ │
│  │          $store->moveToDeadLetter()                       │ │
│  │        else                                               │ │
│  │          $store->markAsFailed()                           │ │
│  │      }                                                   │ │
│  │    }                                                     │ │
│  │    sleep($pollInterval)                                   │ │
│  │  }                                                       │ │
│  └─────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────┘

                           ↕ (message broker)

┌──────────────────────────────────────────────────────────────┐
│                    MESSAGE BROKER                             │
│                                                              │
│  Redis Queue / Amazon SQS / RabbitMQ / Kafka / etc.          │
│                                                              │
│  → Downstream consumers process events (must be idempotent)  │
└──────────────────────────────────────────────────────────────┘
```

### Message Lifecycle

```
                    ┌─────────┐
          store()   │ PENDING │
          ─────────>│         │
                    └────┬────┘
                         │
                   fetch pending
                         │
                    ┌────▼──────┐
                    │PROCESSING │
                    │ (locked)  │
                    └────┬──────┘
                         │
              ┌──────────┴──────────┐
              │                     │
         publish OK            publish FAIL
              │                     │
        ┌─────▼─────┐         ┌────▼─────┐
        │ PUBLISHED  │         │  FAILED  │◄──┐
        │ (terminal) │         │          │   │
        └────────────┘         └────┬─────┘   │
                                    │          │
                          ┌─────────┴────┐     │
                          │              │     │
                    can retry?     exhausted?   │
                          │              │     │
                     ┌────▼────┐   ┌─────▼──────┐
                     │ PENDING │   │ DEAD_LETTER │
                     │(re-queued)  │ (terminal)  │
                     └─────────┘   └─────────────┘
```

### Concurrency Model

Multiple relay workers can run simultaneously thanks to `SELECT ... FOR UPDATE SKIP LOCKED`:

```
Worker 1:  SELECT ... FOR UPDATE SKIP LOCKED → Gets rows [1, 2, 3]
Worker 2:  SELECT ... FOR UPDATE SKIP LOCKED → Gets rows [4, 5, 6]  (skips locked 1,2,3)
Worker 3:  SELECT ... FOR UPDATE SKIP LOCKED → Gets rows [7, 8, 9]  (skips locked 1-6)

Each worker processes its own set — no duplicates, no blocking.
```

### Extension Points

| Interface | Purpose | Default Impl | Custom Examples |
|-----------|---------|--------------|-----------------|
| `OutboxStore` | Message persistence | `PdoOutboxStore` | Doctrine DBAL, Redis, DynamoDB |
| `OutboxPublisher` | Broker publishing | `LaravelQueuePublisher` | RabbitMQ, Kafka, AWS SNS |
| `OutboxSerializer` | Payload encoding | `JsonSerializer` | Avro, Protobuf, MessagePack |
| `IdGenerator` | Message ID creation | `UuidV7Generator` | ULID, Snowflake, custom |

### Retry Strategy

```
Attempt 1: Immediate
Attempt 2: +2s backoff (retryBackoffMs × 1)
Attempt 3: +4s backoff (retryBackoffMs × 2)
Attempt 4: +6s backoff (retryBackoffMs × 3)
Attempt 5: Dead letter (maxAttempts reached)
```

Failed messages are marked as `failed` and picked up again by the next relay cycle. The relay fetches both `pending` and `failed` status messages.

### Why Not CDC?

Change Data Capture (tailing the database's transaction log via Debezium) is more efficient than polling but requires significant infrastructure (Kafka Connect, Debezium, etc.). The polling approach is:

- **Simple to deploy** — just run `php artisan outbox:relay`
- **No infrastructure dependencies** — no Kafka, no Debezium
- **Good enough** for 99% of PHP applications (sub-second latency)
- **Easy to debug** — SQL queries vs binary log parsing

CDC support may be added in future versions as an alternative relay strategy.
