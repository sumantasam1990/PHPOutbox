<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Contracts;

use PhpOutbox\Outbox\Exception\PublishException;
use PhpOutbox\Outbox\Message\OutboxMessage;

/**
 * Contract for publishing outbox messages to an external message broker.
 *
 * Implementations should throw PublishException on failure so the relay
 * can retry the message according to its backoff strategy.
 */
interface OutboxPublisher
{
    /**
     * Publish a single message to the external broker.
     *
     * @throws PublishException If the message could not be published
     */
    public function publish(OutboxMessage $message): void;

    /**
     * Publish multiple messages to the external broker in a batch.
     *
     * Default implementation dispatches one-by-one; override for
     * brokers that support native batch publishing.
     *
     * @param OutboxMessage[] $messages
     * @throws PublishException If any message could not be published
     */
    public function publishBatch(array $messages): void;
}
