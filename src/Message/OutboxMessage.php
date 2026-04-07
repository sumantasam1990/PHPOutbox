<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Message;

use DateTimeImmutable;
use PhpOutbox\Outbox\Enum\OutboxMessageStatus;

/**
 * Immutable value object representing a single outbox message.
 *
 * This is the core data structure that flows through the entire pipeline:
 * Application → OutboxStore → OutboxRelay → OutboxPublisher
 */
readonly class OutboxMessage
{
    /**
     * @param string                  $id           Unique, time-ordered identifier (UUID v7 / ULID)
     * @param string                  $aggregateType The domain aggregate type (e.g., "Order", "User")
     * @param string                  $aggregateId   The specific aggregate instance ID
     * @param string                  $eventType     The event name (e.g., "OrderCreated", "UserRegistered")
     * @param string                  $payload       Serialized event payload (JSON by default)
     * @param array<string, string>   $headers       Metadata headers (correlation ID, causation ID, content-type, etc.)
     * @param OutboxMessageStatus     $status        Current lifecycle status
     * @param int                     $attempts      Number of publish attempts made
     * @param string|null             $lastError     Last error message if failed
     * @param DateTimeImmutable       $createdAt     When the message was stored
     * @param DateTimeImmutable|null  $processedAt   When the message was published or dead-lettered
     */
    public function __construct(
        public string $id,
        public string $aggregateType,
        public string $aggregateId,
        public string $eventType,
        public string $payload,
        public array $headers,
        public OutboxMessageStatus $status,
        public int $attempts,
        public ?string $lastError,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $processedAt,
    ) {
    }

    /**
     * Create a new pending message ready to be stored.
     *
     * @param string                $id
     * @param string                $aggregateType
     * @param string                $aggregateId
     * @param string                $eventType
     * @param string                $payload
     * @param array<string, string> $headers
     * @param DateTimeImmutable     $createdAt
     */
    public static function create(
        string $id,
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        string $payload,
        array $headers,
        DateTimeImmutable $createdAt,
    ): self {
        return new self(
            id: $id,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            eventType: $eventType,
            payload: $payload,
            headers: $headers,
            status: OutboxMessageStatus::Pending,
            attempts: 0,
            lastError: null,
            createdAt: $createdAt,
            processedAt: null,
        );
    }

    /**
     * Return a copy with a different status.
     */
    public function withStatus(OutboxMessageStatus $status): self
    {
        return new self(
            id: $this->id,
            aggregateType: $this->aggregateType,
            aggregateId: $this->aggregateId,
            eventType: $this->eventType,
            payload: $this->payload,
            headers: $this->headers,
            status: $status,
            attempts: $this->attempts,
            lastError: $this->lastError,
            createdAt: $this->createdAt,
            processedAt: $this->processedAt,
        );
    }

    /**
     * Return a copy with an incremented attempt count and error.
     */
    public function withFailure(string $error): self
    {
        return new self(
            id: $this->id,
            aggregateType: $this->aggregateType,
            aggregateId: $this->aggregateId,
            eventType: $this->eventType,
            payload: $this->payload,
            headers: $this->headers,
            status: OutboxMessageStatus::Failed,
            attempts: $this->attempts + 1,
            lastError: $error,
            createdAt: $this->createdAt,
            processedAt: $this->processedAt,
        );
    }

    /**
     * Decode the JSON payload to an associative array.
     *
     * @return array<string, mixed>
     */
    public function decodedPayload(): array
    {
        $decoded = \json_decode($this->payload, true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }
}
