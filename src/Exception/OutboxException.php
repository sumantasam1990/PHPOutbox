<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Exception;

/**
 * Base exception for all outbox-related errors.
 *
 * Catch this to handle any outbox error generically.
 */
class OutboxException extends \RuntimeException
{
    public static function wrap(string $message, \Throwable $previous): self
    {
        return new self($message, (int) $previous->getCode(), $previous);
    }
}
