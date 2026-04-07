<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Exception;

/**
 * Thrown when payload serialization or deserialization fails.
 */
class SerializationException extends OutboxException
{
    public static function serializeFailed(string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            \sprintf('Failed to serialize outbox payload: %s', $reason),
            0,
            $previous,
        );
    }

    public static function deserializeFailed(string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            \sprintf('Failed to deserialize outbox payload: %s', $reason),
            0,
            $previous,
        );
    }
}
