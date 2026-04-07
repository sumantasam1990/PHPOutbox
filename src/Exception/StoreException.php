<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Exception;

/**
 * Thrown when a database/store operation fails.
 */
class StoreException extends OutboxException
{
    public static function storeFailed(string $messageId, \Throwable $previous): self
    {
        return new self(
            \sprintf('Failed to store outbox message [%s]: %s', $messageId, $previous->getMessage()),
            (int) $previous->getCode(),
            $previous,
        );
    }

    public static function fetchFailed(\Throwable $previous): self
    {
        return new self(
            \sprintf('Failed to fetch pending outbox messages: %s', $previous->getMessage()),
            (int) $previous->getCode(),
            $previous,
        );
    }

    public static function updateFailed(string $messageId, string $operation, \Throwable $previous): self
    {
        return new self(
            \sprintf('Failed to %s outbox message [%s]: %s', $operation, $messageId, $previous->getMessage()),
            (int) $previous->getCode(),
            $previous,
        );
    }
}
