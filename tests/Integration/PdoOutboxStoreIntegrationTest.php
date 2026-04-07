<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Tests\Integration;

use DateTimeImmutable;
use PDO;
use PhpOutbox\Outbox\Contracts\IdGenerator;
use PhpOutbox\Outbox\IdGenerator\UuidV7Generator;
use PhpOutbox\Outbox\Message\OutboxMessage;
use PhpOutbox\Outbox\Outbox;
use PhpOutbox\Outbox\OutboxConfig;
use PhpOutbox\Outbox\Serializer\JsonSerializer;
use PhpOutbox\Outbox\Store\PdoOutboxStore;
use PhpOutbox\Outbox\Store\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration test exercising the full store → fetch → update lifecycle
 * using a real SQLite in-memory database.
 */
#[CoversClass(PdoOutboxStore::class)]
#[CoversClass(Outbox::class)]
final class PdoOutboxStoreIntegrationTest extends TestCase
{
    private PDO $pdo;
    private PdoOutboxStore $store;
    private Outbox $outbox;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(Schema::sqlite());

        $config = new OutboxConfig(batchSize: 50);
        $this->store = new PdoOutboxStore($this->pdo, $config);
        $this->outbox = new Outbox(
            store: $this->store,
            serializer: new JsonSerializer(),
            idGenerator: new UuidV7Generator(),
        );
    }

    #[Test]
    public function full_lifecycle_store_fetch_publish(): void
    {
        // 1. Store messages within a transaction
        $this->store->beginTransaction();

        $msg = $this->outbox->store(
            aggregateType: 'Order',
            aggregateId: 'order-42',
            eventType: 'OrderCreated',
            payload: ['total' => 199.99, 'currency' => 'USD'],
            headers: ['correlation-id' => 'req-abc'],
        );

        $this->store->commit();

        self::assertNotEmpty($msg->id);
        self::assertSame('Order', $msg->aggregateType);

        // 2. Fetch pending
        $this->store->beginTransaction();
        $pending = $this->store->fetchPending(100);
        $this->store->commit();

        self::assertCount(1, $pending);
        self::assertSame($msg->id, $pending[0]->id);
        self::assertSame('{"total":199.99,"currency":"USD"}', $pending[0]->payload);
        self::assertSame('req-abc', $pending[0]->headers['correlation-id']);

        // 3. Mark as published
        $this->store->markAsPublished($msg->id);

        // 4. Verify not fetched again
        $this->store->beginTransaction();
        $pending2 = $this->store->fetchPending(100);
        $this->store->commit();

        self::assertCount(0, $pending2);
    }

    #[Test]
    public function store_many_and_fetch_in_order(): void
    {
        $messages = $this->outbox->storeMany([
            [
                'aggregate_type' => 'User',
                'aggregate_id' => 'user-1',
                'event_type' => 'UserCreated',
                'payload' => ['name' => 'Alice'],
            ],
            [
                'aggregate_type' => 'User',
                'aggregate_id' => 'user-2',
                'event_type' => 'UserCreated',
                'payload' => ['name' => 'Bob'],
            ],
            [
                'aggregate_type' => 'Order',
                'aggregate_id' => 'order-1',
                'event_type' => 'OrderPlaced',
                'payload' => ['total' => 50.0],
            ],
        ]);

        self::assertCount(3, $messages);

        $this->store->beginTransaction();
        $pending = $this->store->fetchPending(100);
        $this->store->commit();

        self::assertCount(3, $pending);
    }

    #[Test]
    public function retry_flow_failed_then_retry_then_publish(): void
    {
        $msg = $this->outbox->store(
            aggregateType: 'Payment',
            aggregateId: 'pay-1',
            eventType: 'PaymentProcessed',
            payload: ['amount' => 100],
        );

        // First attempt fails
        $this->store->beginTransaction();
        $this->store->fetchPending(100);
        $this->store->commit();

        $this->store->markAsFailed($msg->id, 'Gateway timeout');

        // Should be fetchable again (status = failed)
        $this->store->beginTransaction();
        $retried = $this->store->fetchPending(100);
        $this->store->commit();

        self::assertCount(1, $retried);
        self::assertSame(1, $retried[0]->attempts);
        self::assertSame('Gateway timeout', $retried[0]->lastError);

        // Second attempt succeeds
        $this->store->markAsPublished($msg->id);

        // Should not be fetchable anymore
        $this->store->beginTransaction();
        $empty = $this->store->fetchPending(100);
        $this->store->commit();

        self::assertCount(0, $empty);
    }

    #[Test]
    public function dead_letter_flow(): void
    {
        $msg = $this->outbox->store(
            aggregateType: 'Notification',
            aggregateId: 'notif-1',
            eventType: 'NotificationSent',
            payload: ['channel' => 'email'],
        );

        // Simulate multiple failures
        $this->store->markAsFailed($msg->id, 'Attempt 1');
        $this->store->markAsFailed($msg->id, 'Attempt 2');
        $this->store->markAsFailed($msg->id, 'Attempt 3');

        // Dead letter
        $this->store->moveToDeadLetter($msg->id, 'Exhausted retries');

        // Should not be fetchable
        $this->store->beginTransaction();
        $pending = $this->store->fetchPending(100);
        $this->store->commit();

        self::assertCount(0, $pending);

        // Verify status
        $counts = $this->store->getCounts();
        self::assertSame(1, $counts['dead_letter'] ?? 0);
    }

    #[Test]
    public function atomic_transaction_rollback(): void
    {
        // Create business table outside transaction (DDL auto-commits in most DBs)
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS "orders" ("id" INTEGER PRIMARY KEY)');

        $this->store->beginTransaction();

        // Simulate business logic + outbox write in same transaction
        $this->pdo->exec('INSERT INTO "orders" ("id") VALUES (1)');

        $this->outbox->store(
            aggregateType: 'Order',
            aggregateId: '1',
            eventType: 'OrderCreated',
            payload: ['item' => 'widget'],
        );

        // Rollback — both business data and outbox message should be gone
        $this->store->rollBack();

        $orderCount = (int) $this->pdo->query('SELECT COUNT(*) FROM "orders"')->fetchColumn();
        self::assertSame(0, $orderCount);

        $outboxCount = (int) $this->pdo->query('SELECT COUNT(*) FROM "outbox_messages"')->fetchColumn();
        self::assertSame(0, $outboxCount);
    }

    #[Test]
    public function prune_removes_old_published_messages_only(): void
    {
        // Store 3 messages
        $msg1 = $this->outbox->store('A', 'a-1', 'E1', ['v' => 1]);
        $msg2 = $this->outbox->store('A', 'a-2', 'E2', ['v' => 2]);
        $msg3 = $this->outbox->store('A', 'a-3', 'E3', ['v' => 3]);

        // Publish msg1 and msg2
        $this->store->markAsPublished($msg1->id);
        $this->store->markAsPublished($msg2->id);

        // Backdate msg1's processed_at
        $oldDate = (new DateTimeImmutable('-60 days'))->format('Y-m-d H:i:s.u');
        $this->pdo->prepare('UPDATE "outbox_messages" SET processed_at = ? WHERE id = ?')
            ->execute([$oldDate, $msg1->id]);

        // Prune messages older than 30 days
        $pruned = $this->store->prune(
            \PhpOutbox\Outbox\Enum\OutboxMessageStatus::Published,
            new DateTimeImmutable('-30 days'),
        );

        self::assertSame(1, $pruned); // Only msg1
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM "outbox_messages"')->fetchColumn();
        self::assertSame(2, $total); // msg2 (published recent) + msg3 (pending)
    }

    #[Test]
    public function content_type_header_is_auto_injected(): void
    {
        $msg = $this->outbox->store(
            aggregateType: 'Test',
            aggregateId: 'test-1',
            eventType: 'TestEvent',
            payload: ['key' => 'value'],
        );

        self::assertSame('application/json', $msg->headers['content-type']);
    }

    #[Test]
    public function custom_headers_are_preserved(): void
    {
        $msg = $this->outbox->store(
            aggregateType: 'Test',
            aggregateId: 'test-1',
            eventType: 'TestEvent',
            payload: ['key' => 'value'],
            headers: [
                'x-custom' => 'my-value',
                'content-type' => 'application/xml', // Override default
            ],
        );

        self::assertSame('my-value', $msg->headers['x-custom']);
        self::assertSame('application/xml', $msg->headers['content-type']);
    }
}
