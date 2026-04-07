<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox;

use DateTimeImmutable;
use PhpOutbox\Outbox\Contracts\IdGenerator;
use PhpOutbox\Outbox\Contracts\OutboxSerializer;
use PhpOutbox\Outbox\Contracts\OutboxStore;
use PhpOutbox\Outbox\IdGenerator\UuidV7Generator;
use PhpOutbox\Outbox\Message\OutboxMessage;
use PhpOutbox\Outbox\Serializer\JsonSerializer;
use Psr\Clock\ClockInterface;

/**
 * Main entry point for storing outbox messages.
 *
 * This is the class your application code interacts with. Call store() inside
 * your database transaction to atomically persist the event alongside your
 * business data.
 *
 * Usage:
 *   // Inside a DB transaction
 *   $outbox->store('Order', $orderId, 'OrderCreated', ['total' => 99.99]);
 */
final class Outbox
{
    private readonly OutboxSerializer $serializer;
    private readonly IdGenerator $idGenerator;
    private readonly ?ClockInterface $clock;

    public function __construct(
        private readonly OutboxStore $store,
        ?OutboxSerializer $serializer = null,
        ?IdGenerator $idGenerator = null,
        ?ClockInterface $clock = null,
    ) {
        $this->serializer = $serializer ?? new JsonSerializer();
        $this->idGenerator = $idGenerator ?? new UuidV7Generator();
        $this->clock = $clock;
    }

    /**
     * Store a single outbox message.
     *
     * IMPORTANT: Call this within the same database transaction as your
     * business logic write. This is the atomic guarantee.
     *
     * @param string                $aggregateType Domain aggregate (e.g., "Order", "User")
     * @param string                $aggregateId   Aggregate instance ID (e.g., "order-123")
     * @param string                $eventType     Event name (e.g., "OrderCreated")
     * @param array<mixed>|string   $payload       Event data — array (will be serialized) or pre-serialized string
     * @param array<string, string> $headers       Optional metadata headers
     */
    public function store(
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        array|string $payload,
        array $headers = [],
    ): OutboxMessage {
        $serializedPayload = $this->serializer->serialize($payload);

        // Inject content-type header if not present
        if (!isset($headers['content-type'])) {
            $headers['content-type'] = $this->serializer->contentType();
        }

        $message = OutboxMessage::create(
            id: $this->idGenerator->generate(),
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            eventType: $eventType,
            payload: $serializedPayload,
            headers: $headers,
            createdAt: $this->now(),
        );

        $this->store->store($message);

        return $message;
    }

    /**
     * Store multiple outbox messages in a single batch.
     *
     * @param array<int, array{
     *     aggregate_type: string,
     *     aggregate_id: string,
     *     event_type: string,
     *     payload: array<mixed>|string,
     *     headers?: array<string, string>,
     * }> $items
     * @return OutboxMessage[]
     */
    public function storeMany(array $items): array
    {
        $messages = [];
        $now = $this->now();

        foreach ($items as $item) {
            $serializedPayload = $this->serializer->serialize($item['payload']);
            $headers = $item['headers'] ?? [];

            if (!isset($headers['content-type'])) {
                $headers['content-type'] = $this->serializer->contentType();
            }

            $messages[] = OutboxMessage::create(
                id: $this->idGenerator->generate(),
                aggregateType: $item['aggregate_type'],
                aggregateId: $item['aggregate_id'],
                eventType: $item['event_type'],
                payload: $serializedPayload,
                headers: $headers,
                createdAt: $now,
            );
        }

        $this->store->storeMany($messages);

        return $messages;
    }

    /**
     * Get the underlying store instance.
     */
    public function getStore(): OutboxStore
    {
        return $this->store;
    }

    private function now(): DateTimeImmutable
    {
        if ($this->clock !== null) {
            return $this->clock->now();
        }

        return new DateTimeImmutable('now');
    }
}
