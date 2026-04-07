<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Tests\Unit\Store;

use DateTimeImmutable;
use PDO;
use PhpOutbox\Outbox\Enum\OutboxMessageStatus;
use PhpOutbox\Outbox\Message\OutboxMessage;
use PhpOutbox\Outbox\OutboxConfig;
use PhpOutbox\Outbox\Store\PdoOutboxStore;
use PhpOutbox\Outbox\Store\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PdoOutboxStore::class)]
#[CoversClass(Schema::class)]
final class PdoOutboxStoreTest extends TestCase
{
    private PDO $pdo;
    private PdoOutboxStore $store;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(Schema::sqlite());

        $this->store = new PdoOutboxStore($this->pdo);
    }

    #[Test]
    public function it_stores_a_message(): void
    {
        $message = $this->createMessage('msg-001');

        $this->store->store($message);

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM "outbox_messages"');
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    #[Test]
    public function it_stores_multiple_messages(): void
    {
        $messages = [
            $this->createMessage('msg-001'),
            $this->createMessage('msg-002'),
            $this->createMessage('msg-003'),
        ];

        $this->store->storeMany($messages);

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM "outbox_messages"');
        self::assertSame(3, (int) $stmt->fetchColumn());
    }

    #[Test]
    public function store_many_does_nothing_for_empty_array(): void
    {
        $this->store->storeMany([]);

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM "outbox_messages"');
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    #[Test]
    public function it_fetches_pending_messages(): void
    {
        $this->store->store($this->createMessage('msg-001'));
        $this->store->store($this->createMessage('msg-002'));

        $this->store->beginTransaction();
        $messages = $this->store->fetchPending(10);
        $this->store->commit();

        self::assertCount(2, $messages);
        self::assertSame('msg-001', $messages[0]->id);
        self::assertSame('msg-002', $messages[1]->id);
    }

    #[Test]
    public function it_fetches_pending_messages_in_order(): void
    {
        $msg1 = OutboxMessage::create(
            id: 'msg-001',
            aggregateType: 'Order',
            aggregateId: 'order-1',
            eventType: 'OrderCreated',
            payload: '{}',
            headers: [],
            createdAt: new DateTimeImmutable('2026-01-15 10:00:00'),
        );

        $msg2 = OutboxMessage::create(
            id: 'msg-002',
            aggregateType: 'Order',
            aggregateId: 'order-2',
            eventType: 'OrderCreated',
            payload: '{}',
            headers: [],
            createdAt: new DateTimeImmutable('2026-01-15 09:00:00'), // Earlier
        );

        $this->store->store($msg1);
        $this->store->store($msg2);

        $this->store->beginTransaction();
        $messages = $this->store->fetchPending(10);
        $this->store->commit();

        // msg-002 was created earlier, so it should come first
        self::assertSame('msg-002', $messages[0]->id);
        self::assertSame('msg-001', $messages[1]->id);
    }

    #[Test]
    public function it_respects_batch_size(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->store->store($this->createMessage("msg-{$i}"));
        }

        $this->store->beginTransaction();
        $messages = $this->store->fetchPending(3);
        $this->store->commit();

        self::assertCount(3, $messages);
    }

    #[Test]
    public function it_marks_as_processing_on_fetch(): void
    {
        $this->store->store($this->createMessage('msg-001'));

        $this->store->beginTransaction();
        $this->store->fetchPending(10);
        $this->store->commit();

        $stmt = $this->pdo->prepare('SELECT status FROM "outbox_messages" WHERE id = ?');
        $stmt->execute(['msg-001']);
        self::assertSame('processing', $stmt->fetchColumn());
    }

    #[Test]
    public function it_does_not_fetch_published_messages(): void
    {
        $this->store->store($this->createMessage('msg-001'));
        $this->store->markAsPublished('msg-001');

        $this->store->beginTransaction();
        $messages = $this->store->fetchPending(10);
        $this->store->commit();

        self::assertCount(0, $messages);
    }

    #[Test]
    public function it_marks_messages_as_published(): void
    {
        $this->store->store($this->createMessage('msg-001'));
        $this->store->markAsPublished('msg-001');

        $stmt = $this->pdo->prepare('SELECT status, processed_at FROM "outbox_messages" WHERE id = ?');
        $stmt->execute(['msg-001']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        self::assertSame('published', $row['status']);
        self::assertNotNull($row['processed_at']);
    }

    #[Test]
    public function it_deletes_on_publish_when_configured(): void
    {
        $config = new OutboxConfig(deleteOnPublish: true);
        $store = new PdoOutboxStore($this->pdo, $config);

        $store->store($this->createMessage('msg-001'));
        $store->markAsPublished('msg-001');

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM "outbox_messages"');
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    #[Test]
    public function it_marks_messages_as_failed(): void
    {
        $this->store->store($this->createMessage('msg-001'));
        $this->store->markAsFailed('msg-001', 'Connection refused');

        $stmt = $this->pdo->prepare('SELECT status, attempts, last_error FROM "outbox_messages" WHERE id = ?');
        $stmt->execute(['msg-001']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        self::assertSame('failed', $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertSame('Connection refused', $row['last_error']);
    }

    #[Test]
    public function it_increments_attempts_on_repeated_failures(): void
    {
        $this->store->store($this->createMessage('msg-001'));
        $this->store->markAsFailed('msg-001', 'Error 1');
        $this->store->markAsFailed('msg-001', 'Error 2');
        $this->store->markAsFailed('msg-001', 'Error 3');

        $stmt = $this->pdo->prepare('SELECT attempts, last_error FROM "outbox_messages" WHERE id = ?');
        $stmt->execute(['msg-001']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        self::assertSame(3, (int) $row['attempts']);
        self::assertSame('Error 3', $row['last_error']);
    }

    #[Test]
    public function it_moves_to_dead_letter(): void
    {
        $this->store->store($this->createMessage('msg-001'));
        $this->store->moveToDeadLetter('msg-001', 'Max retries exceeded');

        $stmt = $this->pdo->prepare('SELECT status, last_error, processed_at FROM "outbox_messages" WHERE id = ?');
        $stmt->execute(['msg-001']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        self::assertSame('dead_letter', $row['status']);
        self::assertSame('Max retries exceeded', $row['last_error']);
        self::assertNotNull($row['processed_at']);
    }

    #[Test]
    public function it_fetches_failed_messages_for_retry(): void
    {
        $this->store->store($this->createMessage('msg-001'));
        $this->store->markAsFailed('msg-001', 'Temporary failure');

        $this->store->beginTransaction();
        $messages = $this->store->fetchPending(10);
        $this->store->commit();

        // Failed messages should be picked up (status IN (pending, failed))
        self::assertCount(1, $messages);
        self::assertSame('msg-001', $messages[0]->id);
    }

    #[Test]
    public function it_prunes_old_published_messages(): void
    {
        // Store and publish a message
        $this->store->store($this->createMessage('msg-001'));
        $this->store->markAsPublished('msg-001');

        // Update processed_at to 60 days ago for pruning test
        $oldDate = (new DateTimeImmutable('-60 days'))->format('Y-m-d H:i:s.u');
        $this->pdo->prepare('UPDATE "outbox_messages" SET processed_at = ? WHERE id = ?')
            ->execute([$oldDate, 'msg-001']);

        $pruned = $this->store->prune(OutboxMessageStatus::Published, new DateTimeImmutable('-30 days'));

        self::assertSame(1, $pruned);

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM "outbox_messages"');
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    #[Test]
    public function it_does_not_prune_recent_messages(): void
    {
        $this->store->store($this->createMessage('msg-001'));
        $this->store->markAsPublished('msg-001');

        $pruned = $this->store->prune(OutboxMessageStatus::Published, new DateTimeImmutable('-30 days'));

        self::assertSame(0, $pruned);
    }

    #[Test]
    public function it_gets_counts_by_status(): void
    {
        $this->store->store($this->createMessage('msg-001'));
        $this->store->store($this->createMessage('msg-002'));
        $this->store->store($this->createMessage('msg-003'));
        $this->store->markAsPublished('msg-001');
        $this->store->markAsFailed('msg-002', 'error');

        $counts = $this->store->getCounts();

        self::assertSame(1, $counts['published'] ?? 0);
        self::assertSame(1, $counts['failed'] ?? 0);
        self::assertSame(1, $counts['pending'] ?? 0);
    }

    #[Test]
    public function it_hydrates_messages_with_headers(): void
    {
        $message = OutboxMessage::create(
            id: 'msg-headers',
            aggregateType: 'User',
            aggregateId: 'user-99',
            eventType: 'UserRegistered',
            payload: '{"email":"test@example.com"}',
            headers: ['correlation-id' => 'abc-123', 'content-type' => 'application/json'],
            createdAt: new DateTimeImmutable(),
        );

        $this->store->store($message);

        $this->store->beginTransaction();
        $fetched = $this->store->fetchPending(10);
        $this->store->commit();

        self::assertCount(1, $fetched);
        self::assertSame('abc-123', $fetched[0]->headers['correlation-id']);
        self::assertSame('application/json', $fetched[0]->headers['content-type']);
    }

    #[Test]
    public function transaction_management_works(): void
    {
        $this->store->beginTransaction();
        $this->store->store($this->createMessage('msg-001'));
        $this->store->rollBack();

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM "outbox_messages"');
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    #[Test]
    public function transaction_commit_persists_data(): void
    {
        $this->store->beginTransaction();
        $this->store->store($this->createMessage('msg-001'));
        $this->store->commit();

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM "outbox_messages"');
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    private function createMessage(string $id): OutboxMessage
    {
        return OutboxMessage::create(
            id: $id,
            aggregateType: 'Order',
            aggregateId: 'order-' . $id,
            eventType: 'OrderCreated',
            payload: '{"total":99.99}',
            headers: [],
            createdAt: new DateTimeImmutable(),
        );
    }
}
