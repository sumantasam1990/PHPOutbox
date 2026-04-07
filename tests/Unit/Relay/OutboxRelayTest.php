<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Tests\Unit\Relay;

use DateTimeImmutable;
use PhpOutbox\Outbox\Contracts\OutboxPublisher;
use PhpOutbox\Outbox\Contracts\OutboxStore;
use PhpOutbox\Outbox\Enum\OutboxMessageStatus;
use PhpOutbox\Outbox\Exception\PublishException;
use PhpOutbox\Outbox\Message\OutboxMessage;
use PhpOutbox\Outbox\OutboxConfig;
use PhpOutbox\Outbox\Relay\OutboxRelay;
use PhpOutbox\Outbox\Relay\RelayMetrics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutboxRelay::class)]
#[CoversClass(RelayMetrics::class)]
final class OutboxRelayTest extends TestCase
{
    #[Test]
    public function run_once_returns_empty_metrics_when_no_messages(): void
    {
        $store = $this->createMock(OutboxStore::class);
        $store->expects(self::once())->method('beginTransaction');
        $store->expects(self::once())->method('fetchPending')->with(100)->willReturn([]);
        $store->expects(self::once())->method('commit');

        $publisher = $this->createMock(OutboxPublisher::class);
        $publisher->expects(self::never())->method('publish');

        $relay = new OutboxRelay($store, $publisher);
        $metrics = $relay->runOnce();

        self::assertFalse($metrics->hasActivity());
        self::assertSame(0, $metrics->processed);
        self::assertSame(0, $metrics->published);
        self::assertSame(0, $metrics->failed);
        self::assertSame(0, $metrics->deadLettered);
        self::assertSame(1, $metrics->cycleNumber);
    }

    #[Test]
    public function run_once_publishes_pending_messages(): void
    {
        $msg1 = $this->createMessage('msg-001');
        $msg2 = $this->createMessage('msg-002');

        $store = $this->createMock(OutboxStore::class);
        $store->method('fetchPending')->willReturn([$msg1, $msg2]);
        $store->expects(self::exactly(2))->method('markAsPublished');

        $publisher = $this->createMock(OutboxPublisher::class);
        $publisher->expects(self::exactly(2))->method('publish');

        $relay = new OutboxRelay($store, $publisher);
        $metrics = $relay->runOnce();

        self::assertTrue($metrics->hasActivity());
        self::assertSame(2, $metrics->processed);
        self::assertSame(2, $metrics->published);
        self::assertSame(0, $metrics->failed);
    }

    #[Test]
    public function it_marks_failed_on_publish_exception(): void
    {
        $msg = $this->createMessage('msg-001');

        $store = $this->createMock(OutboxStore::class);
        $store->method('fetchPending')->willReturn([$msg]);
        $store->expects(self::once())->method('markAsFailed')
            ->with('msg-001', self::stringContains('Connection refused'));
        $store->expects(self::never())->method('markAsPublished');

        $publisher = $this->createMock(OutboxPublisher::class);
        $publisher->method('publish')->willThrowException(
            PublishException::failed('msg-001', new \RuntimeException('Connection refused')),
        );

        $relay = new OutboxRelay($store, $publisher, new OutboxConfig(maxAttempts: 5));
        $metrics = $relay->runOnce();

        self::assertSame(1, $metrics->failed);
        self::assertSame(0, $metrics->published);
    }

    #[Test]
    public function it_dead_letters_after_max_attempts(): void
    {
        // Message already has 4 attempts, max is 5
        $msg = new OutboxMessage(
            id: 'msg-001',
            aggregateType: 'Order',
            aggregateId: 'order-1',
            eventType: 'OrderCreated',
            payload: '{}',
            headers: [],
            status: OutboxMessageStatus::Failed,
            attempts: 4,
            lastError: 'Previous error',
            createdAt: new DateTimeImmutable(),
            processedAt: null,
        );

        $store = $this->createMock(OutboxStore::class);
        $store->method('fetchPending')->willReturn([$msg]);
        $store->expects(self::once())->method('moveToDeadLetter')
            ->with('msg-001', self::isType('string'));
        $store->expects(self::never())->method('markAsFailed');

        $publisher = $this->createMock(OutboxPublisher::class);
        $publisher->method('publish')->willThrowException(
            PublishException::failed('msg-001', new \RuntimeException('Still failing')),
        );

        $relay = new OutboxRelay($store, $publisher, new OutboxConfig(maxAttempts: 5));
        $metrics = $relay->runOnce();

        self::assertSame(1, $metrics->deadLettered);
        self::assertSame(0, $metrics->failed);
    }

    #[Test]
    public function it_handles_mixed_success_and_failure(): void
    {
        $msg1 = $this->createMessage('msg-001');
        $msg2 = $this->createMessage('msg-002');
        $msg3 = $this->createMessage('msg-003');

        $store = $this->createMock(OutboxStore::class);
        $store->method('fetchPending')->willReturn([$msg1, $msg2, $msg3]);
        $store->expects(self::exactly(2))->method('markAsPublished');
        $store->expects(self::once())->method('markAsFailed');

        $publisher = $this->createMock(OutboxPublisher::class);
        $callCount = 0;
        $publisher->method('publish')->willReturnCallback(
            function (OutboxMessage $message) use (&$callCount): void {
                $callCount++;
                if ($callCount === 2) {
                    throw PublishException::failed($message->id, new \RuntimeException('Broker down'));
                }
            },
        );

        $relay = new OutboxRelay($store, $publisher, new OutboxConfig(maxAttempts: 5));
        $metrics = $relay->runOnce();

        self::assertSame(3, $metrics->processed);
        self::assertSame(2, $metrics->published);
        self::assertSame(1, $metrics->failed);
    }

    #[Test]
    public function it_handles_unexpected_exceptions(): void
    {
        $msg = $this->createMessage('msg-001');

        $store = $this->createMock(OutboxStore::class);
        $store->method('fetchPending')->willReturn([$msg]);
        $store->expects(self::once())->method('markAsFailed');

        $publisher = $this->createMock(OutboxPublisher::class);
        $publisher->method('publish')->willThrowException(
            new \InvalidArgumentException('Something unexpected'),
        );

        $relay = new OutboxRelay($store, $publisher, new OutboxConfig(maxAttempts: 5));
        $metrics = $relay->runOnce();

        self::assertSame(1, $metrics->failed);
    }

    #[Test]
    public function stop_sets_stopping_flag(): void
    {
        $store = $this->createMock(OutboxStore::class);
        $publisher = $this->createMock(OutboxPublisher::class);

        $relay = new OutboxRelay($store, $publisher);

        self::assertFalse($relay->isStopping());
        $relay->stop();
        self::assertTrue($relay->isStopping());
    }

    #[Test]
    public function cycle_number_increments(): void
    {
        $store = $this->createMock(OutboxStore::class);
        $store->method('fetchPending')->willReturn([]);

        $publisher = $this->createMock(OutboxPublisher::class);

        $relay = new OutboxRelay($store, $publisher);

        self::assertSame(0, $relay->getCycleNumber());

        $relay->runOnce();
        self::assertSame(1, $relay->getCycleNumber());

        $relay->runOnce();
        self::assertSame(2, $relay->getCycleNumber());
    }

    #[Test]
    public function metrics_summary_is_formatted(): void
    {
        $metrics = new RelayMetrics(
            processed: 10,
            published: 8,
            failed: 1,
            deadLettered: 1,
            durationMs: 45.5,
            cycleNumber: 3,
        );

        $summary = $metrics->summary();

        self::assertStringContainsString('Cycle #3', $summary);
        self::assertStringContainsString('10 processed', $summary);
        self::assertStringContainsString('8 published', $summary);
        self::assertStringContainsString('1 failed', $summary);
        self::assertStringContainsString('1 dead-lettered', $summary);
        self::assertStringContainsString('45.5ms', $summary);
    }

    #[Test]
    public function empty_metrics_has_no_activity(): void
    {
        $metrics = RelayMetrics::empty(5);

        self::assertFalse($metrics->hasActivity());
        self::assertSame(0, $metrics->processed);
        self::assertSame(5, $metrics->cycleNumber);
    }

    private function createMessage(string $id): OutboxMessage
    {
        return OutboxMessage::create(
            id: $id,
            aggregateType: 'Order',
            aggregateId: "order-{$id}",
            eventType: 'OrderCreated',
            payload: '{"total":99.99}',
            headers: [],
            createdAt: new DateTimeImmutable(),
        );
    }
}
