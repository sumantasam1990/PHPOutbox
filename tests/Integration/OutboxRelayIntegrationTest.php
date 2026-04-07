<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Tests\Integration;

use DateTimeImmutable;
use PDO;
use PhpOutbox\Outbox\Contracts\OutboxPublisher;
use PhpOutbox\Outbox\Exception\PublishException;
use PhpOutbox\Outbox\Message\OutboxMessage;
use PhpOutbox\Outbox\Outbox;
use PhpOutbox\Outbox\OutboxConfig;
use PhpOutbox\Outbox\Relay\OutboxRelay;
use PhpOutbox\Outbox\Serializer\JsonSerializer;
use PhpOutbox\Outbox\Store\PdoOutboxStore;
use PhpOutbox\Outbox\Store\Schema;
use PhpOutbox\Outbox\IdGenerator\UuidV7Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end integration test: store → relay → publisher.
 */
#[CoversClass(OutboxRelay::class)]
final class OutboxRelayIntegrationTest extends TestCase
{
    private PDO $pdo;
    private PdoOutboxStore $store;
    private Outbox $outbox;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(Schema::sqlite());

        $config = new OutboxConfig(maxAttempts: 3);
        $this->store = new PdoOutboxStore($this->pdo, $config);
        $this->outbox = new Outbox(
            store: $this->store,
            serializer: new JsonSerializer(),
            idGenerator: new UuidV7Generator(),
        );
    }

    #[Test]
    public function end_to_end_store_and_relay(): void
    {
        // Store 3 messages
        $this->store->beginTransaction();
        $this->outbox->store('Order', 'order-1', 'OrderCreated', ['total' => 100]);
        $this->outbox->store('Order', 'order-2', 'OrderCreated', ['total' => 200]);
        $this->outbox->store('User', 'user-1', 'UserRegistered', ['email' => 'test@example.com']);
        $this->store->commit();

        // Track published messages
        $published = [];
        $publisher = new class ($published) implements OutboxPublisher {
            /** @param OutboxMessage[] $published */
            public function __construct(private array &$published)
            {
            }

            public function publish(OutboxMessage $message): void
            {
                $this->published[] = $message;
            }

            public function publishBatch(array $messages): void
            {
                foreach ($messages as $m) {
                    $this->publish($m);
                }
            }
        };

        $relay = new OutboxRelay(
            $this->store,
            $publisher,
            new OutboxConfig(maxAttempts: 3),
        );

        // Run relay
        $metrics = $relay->runOnce();

        self::assertSame(3, $metrics->published);
        self::assertSame(0, $metrics->failed);
        self::assertCount(3, $published);

        // All messages should be published, nothing left to relay
        $metrics2 = $relay->runOnce();
        self::assertSame(0, $metrics2->processed);
    }

    #[Test]
    public function relay_retries_and_eventually_dead_letters(): void
    {
        $this->store->beginTransaction();
        $this->outbox->store('Payment', 'pay-1', 'PaymentFailed', ['reason' => 'insufficient_funds']);
        $this->store->commit();

        // Publisher that always fails
        $failingPublisher = new class () implements OutboxPublisher {
            public int $attemptCount = 0;

            public function publish(OutboxMessage $message): void
            {
                $this->attemptCount++;
                throw PublishException::failed($message->id, new \RuntimeException("Attempt {$this->attemptCount} failed"));
            }

            public function publishBatch(array $messages): void
            {
                foreach ($messages as $m) {
                    $this->publish($m);
                }
            }
        };

        $config = new OutboxConfig(maxAttempts: 3);
        $relay = new OutboxRelay($this->store, $failingPublisher, $config);

        // Attempt 1: fails, marked as failed
        $m1 = $relay->runOnce();
        self::assertSame(1, $m1->failed);
        self::assertSame(0, $m1->deadLettered);

        // Attempt 2: fails again
        $m2 = $relay->runOnce();
        self::assertSame(1, $m2->failed);

        // Attempt 3: max reached, dead lettered
        $m3 = $relay->runOnce();
        self::assertSame(0, $m3->failed);
        self::assertSame(1, $m3->deadLettered);

        // No more messages to process
        $m4 = $relay->runOnce();
        self::assertSame(0, $m4->processed);

        // Verify counts
        $counts = $this->store->getCounts();
        self::assertSame(1, $counts['dead_letter'] ?? 0);
        self::assertSame(3, $failingPublisher->attemptCount);
    }

    #[Test]
    public function relay_handles_partial_batch_failures(): void
    {
        $this->store->beginTransaction();
        $this->outbox->store('A', 'a-1', 'E1', ['v' => 1]);
        $this->outbox->store('A', 'a-2', 'E2', ['v' => 2]);
        $this->outbox->store('A', 'a-3', 'E3', ['v' => 3]);
        $this->store->commit();

        // Publisher that fails on the second message
        $callCount = 0;
        $publisher = new class ($callCount) implements OutboxPublisher {
            public function __construct(private int &$callCount)
            {
            }

            public function publish(OutboxMessage $message): void
            {
                $this->callCount++;
                if ($this->callCount === 2) {
                    throw PublishException::failed($message->id, new \RuntimeException('Selective failure'));
                }
            }

            public function publishBatch(array $messages): void
            {
                foreach ($messages as $m) {
                    $this->publish($m);
                }
            }
        };

        $relay = new OutboxRelay($this->store, $publisher, new OutboxConfig(maxAttempts: 5));
        $metrics = $relay->runOnce();

        self::assertSame(3, $metrics->processed);
        self::assertSame(2, $metrics->published);
        self::assertSame(1, $metrics->failed);
    }
}
