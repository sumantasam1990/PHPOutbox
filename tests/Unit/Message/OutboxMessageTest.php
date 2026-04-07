<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Tests\Unit\Message;

use DateTimeImmutable;
use PhpOutbox\Outbox\Enum\OutboxMessageStatus;
use PhpOutbox\Outbox\Message\OutboxMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutboxMessage::class)]
final class OutboxMessageTest extends TestCase
{
    #[Test]
    public function it_creates_a_pending_message(): void
    {
        $now = new DateTimeImmutable('2026-01-15 10:30:00');

        $message = OutboxMessage::create(
            id: 'msg-001',
            aggregateType: 'Order',
            aggregateId: 'order-123',
            eventType: 'OrderCreated',
            payload: '{"total":99.99}',
            headers: ['correlation-id' => 'corr-abc'],
            createdAt: $now,
        );

        self::assertSame('msg-001', $message->id);
        self::assertSame('Order', $message->aggregateType);
        self::assertSame('order-123', $message->aggregateId);
        self::assertSame('OrderCreated', $message->eventType);
        self::assertSame('{"total":99.99}', $message->payload);
        self::assertSame(['correlation-id' => 'corr-abc'], $message->headers);
        self::assertSame(OutboxMessageStatus::Pending, $message->status);
        self::assertSame(0, $message->attempts);
        self::assertNull($message->lastError);
        self::assertSame($now, $message->createdAt);
        self::assertNull($message->processedAt);
    }

    #[Test]
    public function it_is_immutable(): void
    {
        $message = $this->createTestMessage();

        // Readonly class — properties cannot be modified
        // This test verifies the readonly contract by checking that
        // withStatus returns a new instance
        $updated = $message->withStatus(OutboxMessageStatus::Published);

        self::assertNotSame($message, $updated);
        self::assertSame(OutboxMessageStatus::Pending, $message->status);
        self::assertSame(OutboxMessageStatus::Published, $updated->status);
    }

    #[Test]
    public function with_status_preserves_all_other_fields(): void
    {
        $message = $this->createTestMessage();
        $updated = $message->withStatus(OutboxMessageStatus::Processing);

        self::assertSame($message->id, $updated->id);
        self::assertSame($message->aggregateType, $updated->aggregateType);
        self::assertSame($message->aggregateId, $updated->aggregateId);
        self::assertSame($message->eventType, $updated->eventType);
        self::assertSame($message->payload, $updated->payload);
        self::assertSame($message->headers, $updated->headers);
        self::assertSame($message->attempts, $updated->attempts);
        self::assertSame($message->lastError, $updated->lastError);
        self::assertSame($message->createdAt, $updated->createdAt);
        self::assertSame($message->processedAt, $updated->processedAt);

        self::assertSame(OutboxMessageStatus::Processing, $updated->status);
    }

    #[Test]
    public function with_failure_increments_attempts_and_sets_error(): void
    {
        $message = $this->createTestMessage();

        $failed = $message->withFailure('Connection refused');

        self::assertSame(OutboxMessageStatus::Failed, $failed->status);
        self::assertSame(1, $failed->attempts);
        self::assertSame('Connection refused', $failed->lastError);

        // Chain failures
        $failedAgain = $failed->withFailure('Timeout');
        self::assertSame(2, $failedAgain->attempts);
        self::assertSame('Timeout', $failedAgain->lastError);
    }

    #[Test]
    public function decoded_payload_returns_array(): void
    {
        $message = $this->createTestMessage();
        $decoded = $message->decodedPayload();

        self::assertIsArray($decoded);
        self::assertSame(99.99, $decoded['total']);
        self::assertSame('electronics', $decoded['category']);
    }

    #[Test]
    public function decoded_payload_handles_empty_object(): void
    {
        $now = new DateTimeImmutable();
        $message = OutboxMessage::create(
            id: 'msg-002',
            aggregateType: 'Test',
            aggregateId: 'test-1',
            eventType: 'TestEvent',
            payload: '{}',
            headers: [],
            createdAt: $now,
        );

        self::assertSame([], $message->decodedPayload());
    }

    #[Test]
    public function decoded_payload_throws_on_invalid_json(): void
    {
        $now = new DateTimeImmutable();
        $message = OutboxMessage::create(
            id: 'msg-003',
            aggregateType: 'Test',
            aggregateId: 'test-1',
            eventType: 'TestEvent',
            payload: 'not-json',
            headers: [],
            createdAt: $now,
        );

        $this->expectException(\JsonException::class);
        $message->decodedPayload();
    }

    private function createTestMessage(): OutboxMessage
    {
        return OutboxMessage::create(
            id: 'msg-001',
            aggregateType: 'Order',
            aggregateId: 'order-123',
            eventType: 'OrderCreated',
            payload: '{"total":99.99,"category":"electronics"}',
            headers: ['content-type' => 'application/json'],
            createdAt: new DateTimeImmutable('2026-01-15 10:30:00'),
        );
    }
}
