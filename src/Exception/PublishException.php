<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Exception;

/**
 * Thrown when publishing a message to the broker fails.
 *
 * The relay will retry the message based on its backoff strategy.
 */
class PublishException extends OutboxException
{
    public static function failed(string $messageId, \Throwable $previous): self
    {
        return new self(
            \sprintf('Failed to publish outbox message [%s]: %s', $messageId, $previous->getMessage()),
            (int) $previous->getCode(),
            $previous,
        );
    }

    public static function batchFailed(int $count, \Throwable $previous): self
    {
        return new self(
            \sprintf('Failed to publish batch of %d outbox messages: %s', $count, $previous->getMessage()),
            (int) $previous->getCode(),
            $previous,
        );
    }
}
