<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Laravel\Publishers;

use Illuminate\Support\Facades\Queue;
use PhpOutbox\Outbox\Contracts\OutboxPublisher;
use PhpOutbox\Outbox\Exception\PublishException;
use PhpOutbox\Outbox\Message\OutboxMessage;

/**
 * Publisher that dispatches outbox messages to Laravel Queue.
 *
 * Each outbox message becomes a job on the configured queue connection/name.
 * Your application should have a matching job handler that processes these events.
 */
final class LaravelQueuePublisher implements OutboxPublisher
{
    public function __construct(
        private readonly ?string $queueConnection = null,
        private readonly string $queueName = 'outbox',
    ) {
    }

    public function publish(OutboxMessage $message): void
    {
        try {
            $jobPayload = [
                'outbox_id' => $message->id,
                'aggregate_type' => $message->aggregateType,
                'aggregate_id' => $message->aggregateId,
                'event_type' => $message->eventType,
                'payload' => $message->payload,
                'headers' => $message->headers,
                'created_at' => $message->createdAt->format('Y-m-d\TH:i:s.uP'),
            ];

            Queue::connection($this->queueConnection)
                ->pushRaw(
                    \json_encode($jobPayload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
                    $this->queueName,
                );
        } catch (\Throwable $e) {
            throw PublishException::failed($message->id, $e);
        }
    }

    public function publishBatch(array $messages): void
    {
        foreach ($messages as $message) {
            $this->publish($message);
        }
    }
}
