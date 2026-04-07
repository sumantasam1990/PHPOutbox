<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Enum;

/**
 * Lifecycle status of an outbox message.
 *
 * Flow: Pending → Processing → Published
 *                            → Failed (retried) → Pending (re-queued)
 *                            → Failed (exhausted) → DeadLetter
 */
enum OutboxMessageStatus: string
{
    /** Waiting to be picked up by the relay. */
    case Pending = 'pending';

    /** Currently being processed by a relay worker (locked). */
    case Processing = 'processing';

    /** Successfully published to the message broker. */
    case Published = 'published';

    /** Publishing failed; will be retried if attempts remain. */
    case Failed = 'failed';

    /** Exhausted all retry attempts; requires manual intervention. */
    case DeadLetter = 'dead_letter';

    /**
     * Check if this status represents a terminal state.
     */
    public function isTerminal(): bool
    {
        return $this === self::Published || $this === self::DeadLetter;
    }

    /**
     * Check if this message can be retried.
     */
    public function isRetryable(): bool
    {
        return $this === self::Failed;
    }
}
